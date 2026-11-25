"""Forward a partial body or withhold a real successful origin response."""

import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import sys
import threading
from urllib.parse import unquote, urlsplit

root = Path(sys.argv[1])
backend, mode, origin_port = sys.argv[2], sys.argv[3], int(sys.argv[4])
marker = root / "storage-http-fault.json"
lock = threading.Lock()
claimed = False
hop_headers = {"connection", "proxy-connection", "keep-alive", "transfer-encoding",
               "expect", "upgrade", "te", "trailer", "content-length"}


class Peer(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *_args):
        pass

    def is_target(self, length):
        path = unquote(urlsplit(self.path).path)
        if length < 65536:
            return False
        if backend == "gcs":
            return self.command in ("POST", "PUT") and (
                "/upload/" in path or "upload_id=" in self.path
            )
        return self.command == "PUT" and path.endswith("/tenant-a/receipts/fault-stage.bin")

    def relay(self):
        global claimed
        raw_length = self.headers.get("Content-Length", "0")
        if not raw_length.isdecimal() or self.headers.get("Transfer-Encoding"):
            self.send_error(411)
            return
        length = int(raw_length)
        target = self.is_target(length)
        if target:
            with lock:
                if claimed:
                    with (root / "storage-http-retries.txt").open("a") as output:
                        output.write("repeated mutation denied\n")
                    self.send_error(409)
                    return
                claimed = True
        upstream = http.client.HTTPConnection("127.0.0.1", origin_port, timeout=15)
        self.close_connection = True
        try:
            upstream.putrequest(self.command, self.path, skip_host=True, skip_accept_encoding=True)
            for name, value in self.headers.items():
                if name.lower() not in hop_headers:
                    upstream.putheader(name, value)
            upstream.putheader("Content-Length", str(length))
            upstream.putheader("Connection", "close")
            upstream.endheaders()
            remaining = min(32768, length) if target and mode == "before" else length
            forwarded = 0
            while remaining:
                data = self.rfile.read(min(remaining, 32768))
                if not data:
                    raise RuntimeError("Client truncated before planned boundary")
                upstream.send(data)
                forwarded += len(data)
                remaining -= len(data)
            if target and mode == "before":
                upstream.close()
                marker.write_text(json.dumps({"mode": mode, "forwarded": forwarded, "length": length, "status": None}))
                return
            response = upstream.getresponse()
            data = response.read()
            if target and mode == "after":
                if not 200 <= response.status < 300:
                    raise RuntimeError(f"Origin did not commit target: {response.status}")
                marker.write_text(json.dumps({"mode": mode, "forwarded": forwarded, "length": length, "status": response.status}))
                return
            self.send_response(response.status)
            for name, value in response.getheaders():
                if name.lower() not in hop_headers:
                    if name.lower() == "location":
                        value = value.replace(f"http://127.0.0.1:{origin_port}", "http://127.0.0.1:19002")
                    self.send_header(name, value)
            size = response.getheader("Content-Length", "0") if self.command == "HEAD" else str(len(data))
            self.send_header("Content-Length", size)
            self.send_header("Connection", "close")
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(data)
        finally:
            upstream.close()

    do_GET = do_HEAD = do_PUT = do_POST = do_DELETE = do_PROPFIND = do_MKCOL = do_OPTIONS = relay


with ThreadingHTTPServer(("127.0.0.1", 19002), Peer) as server:
    (root / "storage-http-ready").touch()
    server.serve_forever()
