"""Loopback TLS HTTP peer; records only synthetic request counts."""
import json
import os
import ssl
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit

root = Path(sys.argv[1])
counts = {}
lock = threading.Lock()

class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    def log_message(self, *args):
        pass

    def reply(self, status, payload, headers=()):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        for name, value in headers:
            self.send_header(name, value)
        self.end_headers()
        try:
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
            pass

    def do_GET(self):
        path = urlsplit(self.path).path
        with lock:
            counts[path] = counts.get(path, 0) + 1
            attempt = counts[path]
        if path == "/retry" and attempt < 3:
            self.reply(503, {"attempt": attempt}, [("Retry-After", "0")])
        elif path == "/missing":
            self.reply(404, {"attempt": attempt})
        elif path == "/redirect":
            self.reply(302, {}, [("Location", f"https://127.0.0.1:{other.server_port}/inspect")])
        elif path == "/loop":
            self.reply(302, {}, [("Location", "/loop")])
        elif path == "/cookie":
            self.reply(200, {}, [("Set-Cookie", "lab=abc; Path=/private; Secure; HttpOnly")])
        elif path in ("/inspect", "/private/check", "/private-other"):
            self.reply(200, {"authorization": bool(self.headers.get("Authorization")), "api_key": bool(self.headers.get("X-Lab-Key")), "cookie": bool(self.headers.get("Cookie"))})
        elif path == "/slow":
            time.sleep(2)
            self.reply(200, {"attempt": attempt})
        elif path == "/counts":
            with lock:
                snapshot = dict(counts)
            self.reply(200, snapshot)
        else:
            self.reply(200, {"attempt": attempt})

context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(root / "cert.pem", root / "key.pem")
primary = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
other = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
for server in (primary, other):
    server.socket = context.wrap_socket(server.socket, server_side=True)
    threading.Thread(target=server.serve_forever, daemon=True).start()
(root / "ready.tmp").write_text(json.dumps({"port": primary.server_port, "other": other.server_port}), encoding="utf-8")
os.replace(root / "ready.tmp", root / "ready.json")
threading.Event().wait()
