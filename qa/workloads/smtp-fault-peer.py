"""Loopback SMTP fixture with actual TLS, envelope and DATA settlement boundaries."""
import base64
import hashlib
import json
from pathlib import Path
import socketserver
import ssl
import sys

root, mode, port = Path(sys.argv[1]), sys.argv[2], int(sys.argv[3])
tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
tls.load_cert_chain(root / "cert.pem", root / "key.pem")


class Session(socketserver.BaseRequestHandler):
    def handle(self):
        conn = self.request
        conn.settimeout(10)
        secured = mode == "implicit"
        authenticated = False
        try:
            if secured:
                conn = tls.wrap_socket(conn, server_side=True)
            def reply(line):
                conn.sendall(line.encode() + b"\r\n")
            def read():
                line = bytearray()
                while not line.endswith(b"\r\n"):
                    byte = conn.recv(1)
                    if not byte:
                        return b""
                    line.extend(byte)
                    if len(line) > 65536:
                        raise ValueError("SMTP fixture line limit")
                return bytes(line)
            reply("220 lab.example ESMTP")
            while line := read():
                command = line.rstrip(b"\r\n")
                upper = command.upper()
                if upper.startswith(b"EHLO "):
                    reply("250-lab.example")
                    if mode == "tls" and not secured:
                        reply("250-STARTTLS")
                    if mode in ("tls", "implicit", "downgrade"):
                        reply("250-AUTH PLAIN LOGIN")
                    reply("250 SIZE 1048576")
                elif upper == b"STARTTLS" and mode == "tls" and not secured:
                    reply("220 TLS ready")
                    conn = tls.wrap_socket(conn, server_side=True)
                    secured = True
                elif upper.startswith(b"AUTH "):
                    if not secured:
                        (root / "plaintext-auth").touch()
                        reply("535 rejected")
                        continue
                    if upper.startswith(b"AUTH PLAIN "):
                        authenticated = base64.b64decode(command.split(b" ", 2)[2]) == b"\0lab-user\0lab-password"
                    elif upper == b"AUTH LOGIN":
                        reply("334 VXNlcm5hbWU6")
                        user = base64.b64decode(read().strip())
                        reply("334 UGFzc3dvcmQ6")
                        password = base64.b64decode(read().strip())
                        authenticated = user == b"lab-user" and password == b"lab-password"
                    reply("235 authenticated" if authenticated else "535 rejected")
                elif upper.startswith(b"MAIL FROM:"):
                    if mode in ("tls", "implicit", "downgrade") and not authenticated:
                        reply("530 authentication required")
                    else:
                        reply("250 sender accepted")
                elif upper.startswith(b"RCPT TO:"):
                    reply("250 recipient accepted")
                elif upper == b"DATA":
                    reply("354 end with dot")
                    payload = bytearray()
                    while True:
                        data = read()
                        if not data:
                            return
                        if data == b".\r\n":
                            break
                        payload.extend(data[1:] if data.startswith(b"..") else data)
                        if len(payload) > 1048576:
                            raise ValueError("SMTP fixture DATA limit")
                    if mode == "reject":
                        (root / "reject.observed").touch()
                        reply("451 temporary rejection")
                        continue
                    record = {"sha256": hashlib.sha256(payload).hexdigest(),
                              "bytes": len(payload), "tls": secured, "authenticated": authenticated,
                              "message_id": next((x.decode() for x in payload.splitlines()
                                                  if x.lower().startswith(b"message-id:")), "")}
                    with (root / (mode + ".jsonl")).open("a", encoding="utf-8") as stream:
                        stream.write(json.dumps(record) + "\n")
                        stream.flush()
                    if mode == "lost":
                        return
                    reply("250 queued")
                elif upper == b"RSET":
                    reply("250 reset")
                elif upper == b"QUIT":
                    reply("221 bye")
                    return
                else:
                    reply("500 unsupported")
        except (OSError, ssl.SSLError):
            pass
        finally:
            conn.close()


with socketserver.TCPServer(("127.0.0.1", port), Session) as server:
    (root / (mode + ".ready")).touch()
    server.serve_forever()
