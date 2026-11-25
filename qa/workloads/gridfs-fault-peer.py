"""MongoDB OP_MSG relay for a disposable, standalone GridFS fixture."""
import json
from pathlib import Path
import socket
import socketserver
import struct
import sys
from threading import Lock

from bson import BSON
from pymongo import MongoClient

root = Path(sys.argv[1])
mode = sys.argv[2]
if mode not in ("before", "after"):
    raise ValueError("Unknown fault phase")
observer = MongoClient("mongodb://127.0.0.1:27017/?directConnection=true", serverSelectionTimeoutMS=3000)
db = observer.experiment
guard = Lock()
limit = 32 * 1024 * 1024


def exact(sock, length):
    data = bytearray()
    while len(data) < length:
        part = sock.recv(length - len(data))
        if not part:
            if not data:
                return None
            raise EOFError("Truncated MongoDB frame")
        data.extend(part)
    return bytes(data)


def frame(sock):
    header = exact(sock, 16)
    if header is None:
        return None
    size, request, response, opcode = struct.unpack("<iiii", header)
    if not 16 <= size <= limit:
        raise ValueError("Invalid frame length")
    body = exact(sock, size - 16)
    if body is None:
        raise EOFError("Missing frame body")
    return header + body, request, response, opcode, body


def document(data, offset):
    size = struct.unpack_from("<i", data, offset)[0]
    if size < 5 or offset + size > len(data):
        raise ValueError("Invalid BSON length")
    return BSON(data[offset:offset + size]).decode(), offset + size


def opmsg(body):
    flags = struct.unpack_from("<I", body)[0]
    if flags & 2:
        raise ValueError("Unacknowledged writes are not supported by this fixture")
    end = len(body) - (4 if flags & 1 else 0)
    offset = 4
    command = {}
    while offset < end:
        kind = body[offset]
        offset += 1
        if kind == 0:
            decoded, offset = document(body, offset)
            command.update(decoded)
        elif kind == 1:
            size = struct.unpack_from("<i", body, offset)[0]
            boundary = offset + size
            start = offset + 4
            stop = body.index(b"\0", start, boundary)
            name = body[start:stop].decode()
            offset = stop + 1
            values = []
            while offset < boundary:
                decoded, offset = document(body, offset)
                values.append(decoded)
            if offset != boundary:
                raise ValueError("Invalid BSON sequence")
            command[name] = values
        else:
            raise ValueError("Unexpected OP_MSG section")
    return command


def close_at_boundary(doc, reply=None):
    with guard:
        marker = root / "gridfs-fault.json"
        if marker.exists():
            (root / "gridfs-replayed").touch()
            return
        file_id = doc["_id"]
        chunks = list(db["documents.chunks"].find({"files_id": file_id}).sort("n", 1))
        length = sum(len(chunk["data"]) for chunk in chunks)
        meta = db["documents.files"].find_one({"_id": file_id})
        if not chunks or [chunk["n"] for chunk in chunks] != list(range(len(chunks))):
            raise ValueError("No contiguous persisted chunks at metadata boundary")
        if length != doc["length"]:
            raise ValueError("Persisted chunk length disagrees with metadata")
        if mode == "before" and meta is not None:
            raise ValueError("Withheld file metadata already exists")
        if mode == "after" and (meta is None or reply.get("ok") != 1 or reply.get("writeErrors")):
            raise ValueError("Origin metadata commit not proved")
        evidence = {
            "mode": mode, "bytes": length, "chunks": len(chunks),
            "metadata": meta is not None, "filename": doc["filename"],
        }
        temporary = marker.with_suffix(".tmp")
        temporary.write_text(json.dumps(evidence))
        temporary.replace(marker)


class Relay(socketserver.BaseRequestHandler):
    def handle(self):
        self.request.settimeout(15)
        with socket.create_connection(("127.0.0.1", 27017), timeout=15) as origin:
            while True:
                request = frame(self.request)
                if request is None:
                    return
                wire, identifier, _, opcode, body = request
                target = None
                if opcode == 2013:
                    cmd = opmsg(body)
                    if cmd.get("insert") == "documents.files" and cmd.get("$db") == "experiment":
                        rows = cmd.get("documents", [])
                        if len(rows) != 1 or not rows[0]["filename"].endswith("fault-stage.bin"):
                            raise ValueError("Unexpected target file insert")
                        target = rows[0]
                if target is not None and mode == "before":
                    close_at_boundary(target)
                    return
                origin.sendall(wire)
                response = frame(origin)
                if response is None or response[2] != identifier:
                    raise ValueError("Missing or mismatched origin response")
                if target is not None:
                    if response[3] != 2013:
                        raise ValueError("Unexpected origin response opcode")
                    close_at_boundary(target, opmsg(response[4]))
                    return
                self.request.sendall(response[0])


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


with Server(("127.0.0.1", 27018), Relay) as server:
    (root / "gridfs-ready").touch()
    server.serve_forever()
