"""Loopback SQS-compatible DeleteMessage request/response fault relay."""
import http.client
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

root = Path(sys.argv[1])


class Peer(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *_args):
        pass

    def do_POST(self):
        self.forward()

    def do_GET(self):
        self.forward()

    def forward(self):
        self.close_connection = True
        length = int(self.headers.get("Content-Length", "0"))
        if not 0 <= length <= 2 * 1024 * 1024 or self.headers.get("Transfer-Encoding"):
            self.send_error(400)
            return
        body = self.rfile.read(length)
        if len(body) != length:
            self.send_error(400)
            return
        target = self.headers.get("X-Amz-Target", "")
        content_type = self.headers.get("Content-Type", "")
        query = parse_qs(urlsplit(self.path).query)
        if "application/x-www-form-urlencoded" in content_type:
            query.update(parse_qs(body.decode("utf-8")))
        delete = target.rsplit(".", 1)[-1] == "DeleteMessage" or query.get("Action") == ["DeleteMessage"]
        phase = (root / "phase").read_text().strip()
        marker = root / phase
        if delete and marker.exists():
            (root / ("replayed-" + phase)).touch()
            self.send_error(409)
            return
        if delete and phase == "before":
            marker.write_text("DeleteMessage withheld\n")
            return
        upstream = http.client.HTTPConnection("127.0.0.1", 19324, timeout=10)
        try:
            headers = {k: v for k, v in self.headers.items() if k.lower() not in ("connection", "expect")}
            upstream.request(self.command, self.path, body=body, headers=headers)
            response = upstream.getresponse()
            payload = response.read(2 * 1024 * 1024 + 1)
            if len(payload) > 2 * 1024 * 1024:
                raise ValueError("Origin response too large")
            if delete and phase == "after" and response.status == 200:
                marker.write_text("DeleteMessage 200 response withheld\n")
                return
            self.send_response(response.status)
            for key, value in response.getheaders():
                if key.lower() not in ("connection", "content-length", "transfer-encoding", "server", "date"):
                    self.send_header(key, value)
            self.send_header("Content-Length", str(len(payload)))
            self.send_header("Connection", "close")
            self.end_headers()
            self.wfile.write(payload)
        finally:
            upstream.close()


with ThreadingHTTPServer(("127.0.0.1", 19325), Peer) as server:
    (root / "ready").touch()
    server.serve_forever()
