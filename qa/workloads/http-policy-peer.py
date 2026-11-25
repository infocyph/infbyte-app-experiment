"""Loopback TLS HTTP peer; records only synthetic request counts."""
import json
import hashlib
from email.parser import BytesParser
from email.policy import default
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
mutations = {}
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

    def do_PUT(self):
        length = int(self.headers.get("Content-Length", "0"))
        if not 0 < length <= 2 * 1024 * 1024:
            self.reply(413, {})
            return
        payload = self.rfile.read(length)
        self.reply(200, {"bytes": len(payload), "digest": hashlib.sha256(payload).hexdigest()})

    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0"))
        if not 0 < length <= 2 * 1024 * 1024:
            self.reply(413, {})
            return
        path = urlsplit(self.path).path
        if path.startswith("/mutation/"):
            payload = self.rfile.read(length)
            digest = hashlib.sha256(payload).hexdigest()
            key = self.headers.get("Idempotency-Key", "")
            with lock:
                counts[path] = counts.get(path, 0) + 1
                attempt = counts[path]
                first_digest, first_key = mutations.setdefault(path, (digest, key))
            self.reply(503 if attempt < 3 else 200, {
                "attempt": attempt, "same_body": digest == first_digest,
                "same_key": key == first_key, "key_present": bool(key),
            }, [("Retry-After", "0")])
            return
        prefix = ("Content-Type: " + self.headers["Content-Type"] + "\r\nMIME-Version: 1.0\r\n\r\n").encode()
        message = BytesParser(policy=default).parsebytes(prefix + self.rfile.read(length))
        result = {}
        for part in message.iter_parts():
            name = part.get_param("name", header="content-disposition")
            payload = part.get_payload(decode=True)
            result[name] = {"bytes": len(payload), "digest": hashlib.sha256(payload).hexdigest(), "filename": part.get_filename()}
        self.reply(200, result)

    def do_GET(self):
        path = urlsplit(self.path).path
        with lock:
            counts[path] = counts.get(path, 0) + 1
            attempt = counts[path]
        if path.startswith("/pool/"):
            time.sleep(0.05)
            self.reply(200, {"path": path, "attempt": attempt})
        elif path == "/download":
            payload = bytes(range(256)) * 4096
            self.send_response(200)
            self.send_header("Content-Length", str(len(payload)))
            self.send_header("Content-Type", "application/octet-stream")
            self.end_headers()
            try:
                for offset in range(0, len(payload), 8192):
                    self.wfile.write(payload[offset:offset + 8192])
            except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
                pass
        elif path in ("/retry-deadline", "/retry-cancel"):
            if path == "/retry-cancel":
                (root / "retry-cancel").touch()
            self.reply(503, {"attempt": attempt}, [("Retry-After", "3")])
        elif path == "/proxy-inspect":
            self.reply(200, {"authorization": bool(self.headers.get("Authorization")), "proxy_authorization": bool(self.headers.get("Proxy-Authorization"))})
        elif path == "/retry" and attempt < 3:
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
