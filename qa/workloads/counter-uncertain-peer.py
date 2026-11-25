"""Bounded RESP2 relay for disposable counter mutations."""
from pathlib import Path
import socket
import socketserver
import sys
import threading

root = Path(sys.argv[1])
port = int(sys.argv[2])
seen = set()
lock = threading.Lock()


def frame(reader, depth=0):
    if depth > 8:
        raise ValueError("RESP nesting limit")
    line = reader.readline(65537)
    if not line:
        return None
    if not line.endswith(b"\r\n") or len(line) > 65536:
        raise ValueError("RESP line limit")
    prefix = line[:1]
    if prefix in (b"+", b"-", b":"):
        return line, line[1:-2]
    count = int(line[1:-2])
    if count == -1:
        return line, None
    if prefix == b"$" and 0 <= count <= 1048576:
        value = reader.read(count + 2)
        if len(value) != count + 2 or not value.endswith(b"\r\n"):
            raise ValueError("Truncated RESP bulk")
        return line + value, value[:-2]
    if prefix == b"*" and 0 <= count <= 64:
        parts = [frame(reader, depth + 1) for _ in range(count)]
        return line + b"".join(x[0] for x in parts), [x[1] for x in parts]
    raise ValueError("Unsupported RESP fixture frame")


class Relay(socketserver.BaseRequestHandler):
    def handle(self):
        self.request.settimeout(5)
        with socket.create_connection(("127.0.0.1", port), timeout=5) as origin:
            with self.request.makefile("rb") as incoming, origin.makefile("rb") as outgoing:
                while (request := frame(incoming)) is not None:
                    wire, args = request
                    phase = None
                    if isinstance(args, list) and len(args) >= 4 and args[0].upper() == b"EVAL":
                        phase = {b"cachelayer:counter:lab-uncertain:before": "before",
                                 b"cachelayer:counter:lab-uncertain:after": "after"}.get(args[3])
                    if phase:
                        with lock:
                            if phase in seen:
                                (root / ("replay-" + phase)).touch()
                                return
                            seen.add(phase)
                        if phase == "before":
                            (root / "fault-before").write_text("not forwarded\n")
                            return
                    origin.sendall(wire)
                    response = frame(outgoing)
                    if response is None:
                        return
                    if phase == "after":
                        if response[1] != [b"17", b"0"]:
                            raise AssertionError("Origin did not confirm expected increment")
                        (root / "fault-after").write_text("origin returned 17, response withheld\n")
                        return
                    self.request.sendall(response[0])


with socketserver.ThreadingTCPServer(("127.0.0.1", 17392), Relay) as server:
    server.daemon_threads = True
    (root / "ready").touch()
    server.serve_forever()
