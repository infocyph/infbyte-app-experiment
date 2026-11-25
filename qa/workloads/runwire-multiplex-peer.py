"""Independent hyper-h2 multiplex peer; drives actual shared receive windows."""
import json
from pathlib import Path
import socketserver
import ssl
import sys

from h2.config import H2Configuration
from h2.connection import H2Connection
from h2.errors import ErrorCodes
from h2.events import RequestReceived, StreamEnded, StreamReset

root = Path(sys.argv[1])
contract, count = sys.argv[2], int(sys.argv[3])


def record(kind, **fields):
    with (root / "events.jsonl").open("a", encoding="utf-8") as output:
        output.write(json.dumps({"kind": kind, **fields}) + "\n")


class Peer(socketserver.BaseRequestHandler):
    def handle(self):
        sock = self.request
        sock.settimeout(25)
        assert sock.selected_alpn_protocol() == "h2"
        record("connection", alpn="h2")
        session = H2Connection(config=H2Configuration(client_side=False, header_encoding="utf-8"))
        session.initiate_connection()
        sock.sendall(session.data_to_send())
        requests, pending = {}, {}
        ended = set()
        started = False
        canceled = False
        blocked = False

        while True:
            data = sock.recv(65535)
            if not data:
                return
            for event in session.receive_data(data):
                if isinstance(event, RequestReceived):
                    headers = dict(event.headers)
                    path = headers[":path"]
                    assert headers[":scheme"] == "https"
                    assert headers[":authority"] == f"127.0.0.1:{self.server.server_address[1]}"
                    requests[event.stream_id] = headers
                    record("request", stream=event.stream_id, path=path, method=headers[":method"])
                elif isinstance(event, StreamEnded):
                    ended.add(event.stream_id)
                elif isinstance(event, StreamReset):
                    assert contract == "cancel" and event.stream_id == 1 and event.error_code == ErrorCodes.CANCEL
                    assert not canceled
                    canceled = True
                    record("cancel", stream=event.stream_id)
            if len(ended) == count and not started:
                started = True
                for stream in sorted(ended, reverse=True):
                    index = (stream + 1) // 2
                    headers = requests[stream]
                    assert headers[":path"] == f"/stream/{index}"
                    head = contract == "head" and index % 2 == 1
                    assert headers[":method"] == ("HEAD" if head else "GET")
                    if contract == "reset" and index == 1:
                        session.reset_stream(stream, error_code=ErrorCodes.CANCEL)
                        record("reset", stream=stream)
                        continue
                    body = bytes([64 + index]) * 98304 if contract == "flow" else f"stream-{index}".encode()
                    session.send_headers(stream, [(":status", "200"), ("x-stream-id", str(index)),
                                                  ("content-length", str(len(body)))], end_stream=head)
                    if not head and not (contract == "cancel" and index == 1):
                        pending[stream] = body
            # Send only while both real connection and stream windows permit.
            while pending:
                progress = False
                for stream in list(pending):
                    window = session.local_flow_control_window(stream)
                    size = min(window, session.max_outbound_frame_size, 8192, len(pending[stream]))
                    if size <= 0:
                        continue
                    part, pending[stream] = pending[stream][:size], pending[stream][size:]
                    session.send_data(stream, part, end_stream=not pending[stream])
                    if not pending[stream]:
                        del pending[stream]
                    progress = True
                if not progress:
                    if not blocked:
                        record("window-block")
                        blocked = True
                    break
            if len(ended) == count + 1:
                recovery = max(ended)
                assert not pending and (contract != "cancel" or canceled)
                assert requests[recovery][":path"] == "/recovery" and requests[recovery][":method"] == "GET"
                session.send_headers(recovery, [(":status", "200"), ("x-stream-id", "recovery"),
                                                ("content-length", "9")])
                session.send_data(recovery, b"recovered", end_stream=True)
                sock.sendall(session.data_to_send())
                record("recovered", stream=recovery)
                return
            output = session.data_to_send()
            if output:
                sock.sendall(output)


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
context.set_alpn_protocols(["h2"])
with Server(("127.0.0.1", 0), Peer) as server:
    (root / "port").write_text(str(server.server_address[1]), encoding="ascii")
    server.serve_forever()
