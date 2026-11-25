"""Disposable native HTTP/2 peer, with no production network access."""
import json
import os
import sys
from concurrent.futures import ThreadPoolExecutor
import grpc

server = grpc.server(ThreadPoolExecutor(max_workers=16))
server.add_generic_rpc_handlers((grpc.method_handlers_generic_handler(
    "foundation.Integration",
    {"Unary": grpc.unary_unary_rpc_method_handler(lambda data, context: b"echo:" + data)},
),))
port = server.add_insecure_port("127.0.0.1:0")
if port <= 0:
    raise RuntimeError("Cannot bind loopback gRPC peer")
server.start()
with open(sys.argv[1] + ".tmp", "w", encoding="utf-8") as output:
    json.dump({"port": port}, output)
os.replace(sys.argv[1] + ".tmp", sys.argv[1])
server.wait_for_termination()
