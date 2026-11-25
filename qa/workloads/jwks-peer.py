"""Local TLS issuer: only synthetic public keys and test metadata."""
import json
import ssl
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

root = Path(sys.argv[1])

class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == "/.well-known/openid-configuration":
            issuer = f"https://issuer.example.test:{self.server.server_port}"
            body = {"issuer": issuer, "jwks_uri": issuer + "/jwks"}
            status, cache = 200, "no-store"
        elif self.path == "/jwks":
            state = json.loads((root / "response.json").read_text())
            body, status, cache = state["document"], state["status"], state["cache"]
        else:
            body, status, cache = {}, 404, "no-store"
        data = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", cache)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(root / "cert.pem", root / "key.pem")
server.socket = context.wrap_socket(server.socket, server_side=True)
(root / "port.tmp").write_text(str(server.server_port))
(root / "port.tmp").rename(root / "port")
server.serve_forever()
