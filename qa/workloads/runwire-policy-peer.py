"""Independent HTTP/1.1 redirect and pre-response retry fixture."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import ssl
import sys
import threading
from urllib.parse import urlsplit

root = Path(sys.argv[1])
lock = threading.Lock()
counts = {}


class Peer(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):
        pass

    def dispatch(self):
        origin = "other" if self.server is other else "primary"
        path = urlsplit(self.path).path
        with lock:
            key = (origin, self.path)
            counts[key] = counts.get(key, 0) + 1
            attempt = counts[key]
            with (root / "requests.jsonl").open("a", encoding="utf-8") as output:
                output.write(json.dumps({"origin": origin, "path": self.path, "method": self.command,
                                         "auth": self.headers.get("Authorization") == "Bearer fixture",
                                         "cookie": self.headers.get("Cookie") == "fixture=yes",
                                         "tenant": self.headers.get("X-Tenant") == "private-fixture",
                                         "accept": self.headers.get("Accept") == "text/plain"}) + "\n")
        if path in ("/retry-success", "/retry-exhausted", "/retry-post"):
            if path != "/retry-success" or attempt == 1:
                self.close_connection = True
                return
        if path == "/retry-after-head":
            self.wfile.write(b"HTTP/1.1 200 OK\r\nContent-Length: 10\r\nConnection: close\r\n\r\npart")
            self.close_connection = True
            return
        status, locations = 200, []
        if path.startswith("/redirect-"):
            _, _, code, method = path.replace("/", "-").split("-")
            assert method.upper() == self.command
            status, locations = int(code), ["/target"]
        elif path == "/query" and self.path == "/query?seed=1":
            status, locations = 302, ["?next=https://example.test/path"]
        elif path == "/relative/base/start":
            status, locations = 307, ["../target?value=1"]
        elif path in ("/cross-allow", "/cross-deny"):
            status, locations = 302, [f"http://127.0.0.1:{other.server_port}/target"]
        elif path == "/unsafe-post":
            status, locations = 307, ["/target"]
        elif path == "/loop":
            status, locations = 308, ["/loop"]
        elif path == "/ambiguous":
            status, locations = 302, ["/target", "/different"]
        elif path == "/downgrade":
            status, locations = 302, [f"http://127.0.0.1:{primary.server_port}/target"]
        payload = b"intermediate-not-visible" if locations else b"settled"
        self.send_response(status)
        for location in locations:
            self.send_header("Location", location)
        self.send_header("Content-Length", str(len(payload)))
        self.send_header("Connection", "close")
        self.send_header("X-Lab-Target", origin)
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(payload)
        self.close_connection = True

    do_GET = do_HEAD = do_POST = dispatch


primary = ThreadingHTTPServer(("127.0.0.1", 0), Peer)
other = ThreadingHTTPServer(("127.0.0.1", 0), Peer)
secure = ThreadingHTTPServer(("127.0.0.1", 0), Peer)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(root / "cert.pem", root / "key.pem")
context.set_alpn_protocols(["http/1.1"])
secure.socket = context.wrap_socket(secure.socket, server_side=True)
for server in (primary, other, secure):
    threading.Thread(target=server.serve_forever, daemon=True).start()
(root / "ports").write_text(f"{primary.server_port} {secure.server_port}\n", encoding="ascii")
threading.Event().wait()
