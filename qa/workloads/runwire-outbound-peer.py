"""Independent TLS HTTP/1.1 and hyper-h2 response boundary peer."""
import json
from pathlib import Path
import socketserver
import ssl
import sys
import threading
import time

from h2.config import H2Configuration
from h2.connection import H2Connection
from h2.settings import SettingCodes
from h2.events import RequestReceived, StreamEnded

root = Path(sys.argv[1])
protocol = sys.argv[2]
alpn = "http/1.1" if protocol == "h1" else "h2"
lock = threading.Lock()
normal = b"native-response"
binary = b"\x00\xffABC" * 4096


def reply_contract(path, method):
    case = path.removeprefix("/")
    assert method == ("HEAD" if case == "head" else "GET")
    assert case in {"get", "head", "binary", "no-content", "not-modified", "not-found",
                    "unavailable", "short", "long", "disconnect", "deadline", "cancel", "recovery"}
    status = {"no-content": 204, "not-modified": 304, "not-found": 404, "unavailable": 503}.get(case, 200)
    body = binary if case == "binary" else normal
    length = len(body)
    if case in ("head", "no-content", "not-modified"):
        body = b""
    if case == "short":
        body = b"short"
    elif case == "long":
        length = 3
        body = b"overlong"
    elif case in ("disconnect", "deadline", "cancel"):
        body = b""
    return case, status, body, None if case == "no-content" else length


def evidence(path, method, headers):
    assert headers.get("x-lab-client") == "native"
    with lock:
        with (root / "requests.jsonl").open("a", encoding="utf-8") as output:
            output.write(json.dumps({"path": path, "method": method, "alpn": alpn}) + "\n")


class Peer(socketserver.BaseRequestHandler):
    def handle(self):
        sock = self.request
        sock.settimeout(8)
        if sock.selected_alpn_protocol() != alpn:
            return
        try:
            if protocol == "h1":
                self.http1(sock)
            else:
                self.http2(sock)
        except (BrokenPipeError, ConnectionResetError, TimeoutError, ssl.SSLError):
            pass

    def http1(self, sock):
        raw = b""
        while b"\r\n\r\n" not in raw:
            part = sock.recv(4096)
            if not part:
                return
            raw += part
            assert len(raw) <= 16384
        lines = raw.split(b"\r\n\r\n", 1)[0].decode("ascii").split("\r\n")
        method, path, version = lines[0].split(" ")
        assert version == "HTTP/1.1"
        headers = dict((key.lower(), value.strip()) for key, value in (line.split(":", 1) for line in lines[1:]))
        evidence(path, method, headers)
        case, status, body, length = reply_contract(path, method)
        fields = [f"HTTP/1.1 {status} Fixture", "Connection: close", f"X-Lab-ALPN: {alpn}",
                  "X-Lab-Field: first", "X-Lab-Field: second"]
        if length is not None:
            fields.append(f"Content-Length: {length}")
        head = ("\r\n".join(fields) + "\r\n\r\n").encode("ascii")
        if case in ("deadline", "cancel"):
            sock.sendall(head)
            time.sleep(4)
            sock.sendall(normal)
        else:
            sock.sendall(head + body)

    def http2(self, sock):
        session = H2Connection(config=H2Configuration(client_side=False, header_encoding="utf-8"))
        session.initiate_connection()
        # RFC 9113 section 6.5.2 permits a server to advertise ENABLE_PUSH=0.
        assert session.local_settings[SettingCodes.ENABLE_PUSH] == 0
        print("Initial server SETTINGS:", dict(session.local_settings), flush=True)
        sock.sendall(session.data_to_send())
        requests = {}
        while True:
            data = sock.recv(65535)
            if not data:
                return
            for event in session.receive_data(data):
                if isinstance(event, RequestReceived):
                    requests[event.stream_id] = dict(event.headers)
                elif isinstance(event, StreamEnded):
                    headers = requests[event.stream_id]
                    method, path = headers[":method"], headers[":path"]
                    assert headers[":scheme"] == "https"
                    evidence(path, method, headers)
                    case, status, body, length = reply_contract(path, method)
                    fields = [(":status", str(status)), ("x-lab-alpn", alpn),
                              ("x-lab-field", "first"), ("x-lab-field", "second")]
                    if length is not None:
                        fields.append(("content-length", str(length)))
                    end = case in ("head", "no-content", "not-modified")
                    session.send_headers(event.stream_id, fields, end_stream=end)
                    if case in ("deadline", "cancel", "disconnect"):
                        sock.sendall(session.data_to_send())
                        if case == "disconnect":
                            return
                        time.sleep(4)
                        body = normal
                    if not end:
                        for offset in range(0, len(body), 8192):
                            part = body[offset:offset + 8192]
                            session.send_data(event.stream_id, part, end_stream=offset + len(part) == len(body))
                    sock.sendall(session.data_to_send())
                    return
            pending = session.data_to_send()
            if pending:
                sock.sendall(pending)


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

    def get_request(self):
        sock, address = super().get_request()
        try:
            sock.settimeout(4)
            return context.wrap_socket(sock, server_side=True), address
        except OSError:
            sock.close()
            raise


context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(root / "cert.pem", root / "key.pem")
context.set_alpn_protocols([alpn])
with Server(("127.0.0.1", 0), Peer) as server:
    (root / "port").write_text(str(server.server_address[1]), encoding="ascii")
    server.serve_forever()
