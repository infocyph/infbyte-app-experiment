"""Isolated TLS gRPC peer using actual HTTP/2 call shapes."""
import json
import os
import sys
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import grpc

root = Path(sys.argv[1])

def unary(data, context):
    context.set_trailing_metadata((("x-trailer", "unary"),))
    return data

def server_stream(data, context):
    context.set_trailing_metadata((("x-count", "64"),))
    for _ in range(64):
        if not context.is_active():
            return
        yield data

def client_stream(messages, context):
    count = sum(1 for _ in messages)
    context.set_trailing_metadata((("x-count", str(count)),))
    return b""

def bidi_stream(messages, context):
    count = 0
    for data in messages:
        count += 1
        yield data
    context.set_trailing_metadata((("x-count", str(count)),))

def slow_stream(data, context):
    time.sleep(0.25)
    if context.is_active():
        yield data

server = grpc.server(ThreadPoolExecutor(max_workers=16))
server.add_generic_rpc_handlers((grpc.method_handlers_generic_handler(
    "lab.Stream",
    {
        "Unary": grpc.unary_unary_rpc_method_handler(unary),
        "Server": grpc.unary_stream_rpc_method_handler(server_stream),
        "Client": grpc.stream_unary_rpc_method_handler(client_stream),
        "Bidi": grpc.stream_stream_rpc_method_handler(bidi_stream),
        "Slow": grpc.unary_stream_rpc_method_handler(slow_stream),
    },
),))
credentials = grpc.ssl_server_credentials((((root / "key.pem").read_bytes(), (root / "cert.pem").read_bytes()),))
port = server.add_secure_port("127.0.0.1:0", credentials)
if port <= 0:
    raise RuntimeError("TLS bind failed")
server.start()
(root / "ready.tmp").write_text(json.dumps({"port": port}), encoding="utf-8")
os.replace(root / "ready.tmp", root / "ready.json")
server.wait_for_termination()
