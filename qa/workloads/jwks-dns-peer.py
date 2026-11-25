"""Synthetic DNS and TLS peers; run only inside an isolated network namespace."""
import http.server
import json
import socket
import socketserver
import ssl
import struct
import sys
import threading
from pathlib import Path

root = Path(sys.argv[1])
public_ip = "93.184.216.34"
private_ip = "127.0.0.2"


class Dns(socketserver.BaseRequestHandler):
    def handle(self):
        packet, channel = self.request
        if len(packet) < 17:
            return
        identity, flags, count, _, _, _ = struct.unpack("!6H", packet[:12])
        if count != 1 or flags & 0x8000:
            return
        end = 12
        labels = []
        while end < len(packet):
            size = packet[end]
            end += 1
            if size == 0:
                break
            if size > 63 or end + size > len(packet):
                return
            labels.append(packet[end:end + size].decode("ascii"))
            end += size
        if end + 4 > len(packet):
            return
        host = ".".join(labels).lower()
        kind, family = struct.unpack("!HH", packet[end:end + 4])
        question = packet[12:end + 4]
        phase = (root / "phase").read_text().strip()
        addresses = []
        if family == 1 and kind in (1, 255):
            if host == "issuer.example.test":
                addresses = [private_ip if phase == "private-peer" else public_ip]
            elif host == "private.example.test":
                addresses = [private_ip]
            elif host == "mixed.example.test":
                addresses = [public_ip, private_ip]
        records = b"".join(
            b"\xc0\x0c" + struct.pack("!HHIH", 1, 1, 0, 4) + socket.inet_aton(address)
            for address in addresses
        )
        print(f"dns {host} {kind} {phase} {len(addresses)}", flush=True)
        answer = struct.pack("!6H", identity, 0x8080 | (flags & 0x0100), 1, len(addresses), 0, 0) + question + records
        channel.sendto(answer, self.client_address)


class Issuer(http.server.BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def do_GET(self):
        name = "public" if self.server.server_address[0] == public_ip else "private"
        with (root / f"{name}.requests").open("a") as evidence:
            evidence.write("GET\n")
        body = json.dumps({"keys": []}).encode("ascii")
        self.send_response(200 if self.path == "/jwks" else 404)
        self.send_header("Content-Type", "application/jwk-set+json")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)


class DnsServer(socketserver.UDPServer):
    allow_reuse_address = True


def tls_server(address):
    server = http.server.ThreadingHTTPServer((address, 18443), Issuer)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(root / "cert.pem", root / "key.pem")
    server.socket = context.wrap_socket(server.socket, server_side=True)
    return server


servers = [DnsServer(("127.0.0.1", 53), Dns), tls_server(public_ip), tls_server(private_ip)]
for server in servers:
    threading.Thread(target=server.serve_forever, daemon=True).start()
(root / "ready").touch()
threading.Event().wait()
