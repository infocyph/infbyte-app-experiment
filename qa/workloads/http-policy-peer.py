"""Loopback TLS HTTP peer; records only synthetic request counts."""
import json
import hashlib
import hmac
import base64
from email.parser import BytesParser
from email.policy import default
import os
import ssl
import socket
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

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
            if self.command != "HEAD":
                self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
            pass

    def boundary_redirect(self):
        path = urlsplit(self.path).path
        if not (path.startswith("/redirect-contract/") or path.startswith("/redirect-target/")):
            return False
        length = int(self.headers.get("Content-Length", "0"))
        assert 0 <= length <= 4096
        body = self.rfile.read(length) if length else b""
        with lock:
            counts[path] = counts.get(path, 0) + 1
        if path.startswith("/redirect-contract/"):
            _, _, status, method = path.split("/")
            assert self.command.lower() == method
            assert status in ("301", "302", "303", "307", "308")
            assert body == (b"order\x00receipt\xff" if method in ("post", "put", "patch", "delete") else b"")
            assert bool(self.headers.get("Authorization"))
            assert self.headers.get("Content-Type", "absent") == ("application/octet-stream" if body else "absent")
            self.send_response(int(status))
            self.send_header("Location", f"/redirect-target/{status}/{method}")
            self.send_header("Content-Length", "0")
            self.end_headers()
        else:
            self.reply(200, {}, [
                ("X-Lab-Method", self.command),
                ("X-Lab-Bytes", str(len(body))),
                ("X-Lab-Digest", hashlib.sha256(body).hexdigest()),
                ("X-Lab-Content-Type", self.headers.get("Content-Type", "absent")),
                ("X-Lab-Authorization", "present" if self.headers.get("Authorization") else "absent"),
            ])
        return True

    def retry_matrix(self):
        path = urlsplit(self.path).path
        if not path.startswith("/retry-matrix/"):
            return False
        _, _, group, contract = path.split("/")
        length = int(self.headers.get("Content-Length", "0"))
        assert 0 <= length <= 4096
        payload = self.rfile.read(length) if length else b""
        digest = hashlib.sha256(payload).hexdigest()
        key = self.headers.get("Idempotency-Key", "")
        identity = (self.command, digest, key)
        with lock:
            counts[path] = counts.get(path, 0) + 1
            attempt = counts[path]
            first = mutations.setdefault(path, identity)
        if group == "transport" and attempt < 3:
            self.close_connection = True
            try:
                self.connection.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass
            return True
        status = (int(contract) if group == "status" else 503) if attempt < 3 else 200
        if group == "delay" and contract == "exhausted":
            status = 503
        headers = [
            ("Connection", "close"), ("X-Lab-Attempt", str(attempt)),
            ("X-Lab-Method", self.command), ("X-Lab-Digest", digest),
            ("X-Lab-Same", "yes" if identity == first else "no"),
            ("X-Lab-Key", "yes" if key else "no"),
        ]
        if group == "delay":
            value = {"zero": "0", "past-date": "Wed, 01 Jan 2020 00:00:00 GMT",
                     "capped": "90", "huge": "9999999999999999999999999",
                     "invalid": "not-a-date"}.get(contract)
            if value is not None:
                headers.append(("Retry-After", value))
        elif group == "cancel":
            headers.append(("Retry-After", "3"))
        elif group != "transport":
            headers.append(("Retry-After", "0"))
        self.reply(status, {"attempt": attempt}, headers)
        self.close_connection = True
        return True

    def do_PATCH(self):
        if not self.retry_matrix() and not self.boundary_redirect():
            self.send_error(404)

    def do_DELETE(self):
        if not self.retry_matrix() and not self.boundary_redirect():
            self.send_error(404)

    def do_OPTIONS(self):
        if not self.retry_matrix() and not self.boundary_redirect():
            self.send_error(404)

    def do_HEAD(self):
        if not self.retry_matrix() and not self.boundary_redirect():
            self.send_error(404)

    def do_PUT(self):
        if self.retry_matrix() or self.boundary_redirect():
            return
        length = int(self.headers.get("Content-Length", "0"))
        if not 0 < length <= 2 * 1024 * 1024:
            self.reply(413, {})
            return
        payload = self.rfile.read(length)
        self.reply(200, {"bytes": len(payload), "digest": hashlib.sha256(payload).hexdigest()})

    def do_POST(self):
        if self.retry_matrix() or self.boundary_redirect():
            return
        length = int(self.headers.get("Content-Length", "0"))
        if not 0 < length <= 2 * 1024 * 1024:
            self.reply(413, {})
            return
        path = urlsplit(self.path).path
        if path.startswith("/foundation-webhook"):
            payload = self.rfile.read(length)
            delivery = self.headers.get("X-TB-Delivery", "")
            event = self.headers.get("X-TB-Event", "")
            stamp = self.headers.get("X-TB-Timestamp", "")
            signed = stamp.encode() + b".talkingbytes.webhook.v2\0" + event.encode() + b"\0" + delivery.encode() + b"\0" + payload
            expected = "t=" + stamp + ",v2=" + hmac.new(b"fixture-signing-only", signed, hashlib.sha256).hexdigest()
            digest = hashlib.sha256(payload).hexdigest()
            with lock:
                counts[path] = counts.get(path, 0) + 1
                attempt = counts[path]
                first = mutations.setdefault(path, (digest, delivery))
            valid = (hmac.compare_digest(expected, self.headers.get("X-TB-Signature", ""))
                     and delivery == "foundation-delivery" and event == "orders.created"
                     and self.headers.get("X-TB-Attempt") == str(attempt))
            status = 503 if path.endswith("-retry") and attempt < 3 else 200
            self.reply(status, {"attempt": attempt, "valid": valid, "same_body": first == (digest, delivery),
                                "profile": self.headers.get("X-Lab-Profile")}, [("Retry-After", "0")])
            return
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
        if self.retry_matrix() or self.boundary_redirect():
            return
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
        elif path == "/foundation-profile":
            auth = self.headers.get("Authorization", "")
            if auth == "Bearer fixture-token":
                driver = "bearer"
            elif auth == "Basic " + base64.b64encode(b"fixture:fixture-password").decode():
                driver = "basic"
            elif self.headers.get("X-Lab-Key") == "fixture-key":
                driver = "header"
            elif parse_qs(urlsplit(self.path).query).get("fixture_key") == ["fixture-key"]:
                driver = "query"
            else:
                driver = "none"
            self.reply(200, {"profile": self.headers.get("X-Lab-Profile"), "auth": driver})
        elif path == "/foundation-fail":
            self.reply(503, {"attempt": attempt})
        elif path == "/redirect-query":
            query = parse_qs(urlsplit(self.path).query)
            if query.get("phase") == ["target"]:
                self.reply(200, {"next": query["next"][0], "phase": "target"})
            else:
                self.reply(302, {}, [("Location", "?phase=target&next=https://example.test/continue")])
        elif path == "/redirect-downgrade":
            self.reply(302, {}, [("Location", f"http://127.0.0.1:{plaintext.server_port}/downgrade-target")])
        elif path == "/downgrade-target":
            self.reply(200, {"authorization": bool(self.headers.get("Authorization"))})
        elif path == "/limit-header":
            block = (b"HTTP/1.1 200 OK\r\nContent-Length: 4\r\nContent-Type: text/plain\r\n"
                     b"X-Lab-Field: a\r\nX-Lab-Field: b\r\n\r\nabcd")
            try:
                self.wfile.write(block)
            except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
                pass
        elif path == "/limit-body":
            body = b"abcd" * 1024
            self.send_response(200)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            try:
                self.wfile.write(body)
            except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
                pass
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
plaintext = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
threading.Thread(target=plaintext.serve_forever, daemon=True).start()
for server in (primary, other):
    server.socket = context.wrap_socket(server.socket, server_side=True)
    threading.Thread(target=server.serve_forever, daemon=True).start()
(root / "ready.tmp").write_text(json.dumps({"port": primary.server_port, "other": other.server_port}), encoding="utf-8")
os.replace(root / "ready.tmp", root / "ready.json")
threading.Event().wait()
