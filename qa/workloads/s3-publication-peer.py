"""Disposable loopback S3 fixture: interrupt one PUT body or its response."""

import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import sys

ROOT = Path(sys.argv[1])
TARGETS = {
    "/lab-documents/tenant-a/receipts/interrupted.bin": "interrupted",
    "/lab-documents/tenant-a/receipts/uncertain.bin": "uncertain",
}


class Peer(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *_args):
        pass

    def do_PUT(self):
        mode = TARGETS.get(self.path)
        length = int(self.headers.get("Content-Length", "0"))
        if mode is None or length < 131072 or self.headers.get("Transfer-Encoding"):
            self.send_error(400)
            return
        marker = ROOT / ("s3-" + mode)
        if marker.exists():
            self.send_error(409)
            return
        upstream = http.client.HTTPConnection("127.0.0.1", 19000, timeout=10)
        self.close_connection = True
        try:
            upstream.putrequest("PUT", self.path, skip_host=True, skip_accept_encoding=True)
            for name, value in self.headers.items():
                if name.lower() not in ("connection", "expect"):
                    upstream.putheader(name, value)
            upstream.endheaders()
            remaining = min(length, 65536) if mode == "interrupted" else length
            forwarded = 0
            while remaining:
                chunk = self.rfile.read(min(remaining, 65536))
                if not chunk:
                    raise RuntimeError("Client body truncated before planned fault")
                upstream.send(chunk)
                forwarded += len(chunk)
                remaining -= len(chunk)
            if mode == "interrupted":
                upstream.close()
                marker.write_text(f"{forwarded}/{length}\n")
                return
            response = upstream.getresponse()
            response.read()
            if response.status != 200:
                raise RuntimeError(f"Unexpected upstream PUT status {response.status}")
            marker.write_text(f"{response.status} {forwarded}/{length}\n")
            # The origin committed the request, but the client receives no response.
        finally:
            upstream.close()


with ThreadingHTTPServer(("127.0.0.1", 19001), Peer) as server:
    (ROOT / "s3-peer-ready").touch()
    server.serve_forever()
