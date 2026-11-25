#!/usr/bin/python3
"""Bounded SFTP subsystem relay to native OpenSSH; only fixture rename paths can fault."""
import os
from pathlib import Path
import struct
import subprocess
import sys
import threading

root = Path("/signal")
child = subprocess.Popen(["/usr/lib/openssh/sftp-server"], stdin=subprocess.PIPE, stdout=subprocess.PIPE)
pending = {}
lock = threading.Lock()


def packet(stream):
    header = stream.read(4)
    if not header:
        return None
    if len(header) != 4:
        raise ValueError("Incomplete SFTP header")
    length = struct.unpack("!I", header)[0]
    if not 1 <= length <= 1024 * 1024:
        raise ValueError("SFTP frame exceeds fixture bound")
    body = stream.read(length)
    if len(body) != length:
        raise ValueError("Incomplete SFTP frame")
    return header + body, body


def field(body, offset):
    size = struct.unpack_from("!I", body, offset)[0]
    start = offset + 4
    end = start + size
    if end > len(body):
        raise ValueError("Invalid SFTP field")
    return body[start:end], end


def mark(name, content):
    path = root / name
    # Exclusive creation detects another attempt at the same fault boundary.
    try:
        with path.open("x") as stream:
            stream.write(content + "\n")
    except FileExistsError:
        (root / ("replayed-" + name)).touch()
    os._exit(0)


def requests():
    try:
        while row := packet(sys.stdin.buffer):
            wire, body = row
            kind = body[0]
            if kind in (18, 200):
                request_id = struct.unpack_from("!I", body, 1)[0]
                offset = 5
                if kind == 200:
                    extension, offset = field(body, offset)
                    if extension != b"posix-rename@openssh.com":
                        child.stdin.write(wire)
                        child.stdin.flush()
                        continue
                source, offset = field(body, offset)
                target, _ = field(body, offset)
                phase = (root / "phase").read_text().strip()
                if phase in ("before", "after"):
                    prefix = b"/upload/tenant-a/receipts/" + phase.encode()
                    if source == prefix + b"-stage.bin" and target == prefix + b"-published.bin":
                        if (root / phase).exists():
                            (root / ("replayed-" + phase)).touch()
                            os._exit(0)
                        if phase == "before":
                            mark(phase, "rename request withheld")
                        with lock:
                            pending[request_id] = phase
            child.stdin.write(wire)
            child.stdin.flush()
    finally:
        child.stdin.close()


def responses():
    while row := packet(child.stdout):
        wire, body = row
        if body[0] == 101 and len(body) >= 9:
            request_id, status = struct.unpack_from("!II", body, 1)
            with lock:
                phase = pending.pop(request_id, None)
            if phase is not None and status == 0:
                mark(phase, "native rename success status withheld")
        sys.stdout.buffer.write(wire)
        sys.stdout.buffer.flush()


threading.Thread(target=requests, daemon=True).start()
try:
    responses()
finally:
    child.terminate()
    child.wait(timeout=5)
