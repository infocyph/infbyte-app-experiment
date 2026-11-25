"""Independent H2 client: bounded stream credit, cancellation and sibling isolation."""
import json
import os
from pathlib import Path
import socket
import ssl
import time

from h2.config import H2Configuration
from h2.connection import H2Connection
from h2.events import DataReceived, ResponseReceived, StreamEnded, StreamReset
from h2.settings import SettingCodes

root = Path(os.environ["LAB_WIRE_ROOT"])
context = ssl.create_default_context(cafile=str(root / "cert.pem"))
context.set_alpn_protocols(["h2"])


def eventually(operation, seconds=15):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        result = operation()
        if result:
            return result
        time.sleep(0.03)
    raise AssertionError("Expected flow-control evidence not observed")


def connect():
    try:
        return socket.create_connection(("127.0.0.1", 18785), timeout=2)
    except OSError:
        return None


with eventually(connect) as raw, context.wrap_socket(raw, server_hostname="localhost") as conn:
    assert conn.selected_alpn_protocol() == "h2"
    conn.settimeout(10)
    h = H2Connection(config=H2Configuration(client_side=True, header_encoding="utf-8"))
    h.initiate_connection()
    h.update_settings({SettingCodes.INITIAL_WINDOW_SIZE: 4096})
    conn.sendall(h.data_to_send())
    bodies, statuses, ended = {}, {}, set()

    def send(path, method="GET", end=True):
        sid = h.get_next_available_stream_id()
        bodies[sid] = bytearray()
        h.send_headers(sid, [(":method", method), (":scheme", "https"),
                            (":authority", "localhost:18785"), (":path", path)], end_stream=end)
        return sid

    def flush():
        pending = h.data_to_send()
        if pending:
            conn.sendall(pending)

    def receive(held=()):
        data = conn.recv(65536)
        assert data, "HTTP/2 connection closed"
        for event in h.receive_data(data):
            if isinstance(event, DataReceived):
                bodies[event.stream_id].extend(event.data)
                if event.stream_id not in held:
                    h.acknowledge_received_data(event.flow_controlled_length, event.stream_id)
            elif isinstance(event, ResponseReceived):
                statuses[event.stream_id] = dict(event.headers)[":status"]
            elif isinstance(event, StreamEnded):
                ended.add(event.stream_id)
            elif isinstance(event, StreamReset):
                raise AssertionError(f"Unexpected peer reset: {event.stream_id}")
        flush()

    stream = send("/flow-stream")
    sibling = send("/sibling")
    flush()
    while sibling not in ended or len(bodies[stream]) < 4096:
        receive((stream,))
    assert statuses[sibling] == "200" and bodies[sibling] == b"2:/sibling"
    assert len(bodies[stream]) == 4096 and stream not in ended
    h.acknowledge_received_data(4096, stream)
    flush()
    while stream not in ended:
        receive()
    assert statuses[stream] == "200"
    assert bodies[stream] == b"".join(bytes([i]) * 4096 for i in range(64))

    cancelled = send("/flow-cancel")
    flush()
    while len(bodies[cancelled]) < 4096:
        receive((cancelled,))
    assert cancelled not in ended and len(bodies[cancelled]) == 4096
    h.reset_stream(cancelled, error_code=8)
    # Return the connection-level receive credit consumed by the cancelled stream.
    h.increment_flow_control_window(4096)
    flush()
    eventually(lambda: (root / "response-cancel").exists())

    upload = send("/flow-upload", method="POST", end=False)
    h.send_data(upload, b"partial", end_stream=False)
    flush()
    eventually(lambda: (root / "upload-ready").exists())
    h.reset_stream(upload, error_code=8)
    flush()
    eventually(lambda: (root / "upload-cancel").exists())
    recovery = send("/after-reset")
    flush()
    while recovery not in ended:
        receive()
    assert statuses[recovery] == "200" and bodies[recovery] == b"2:/after-reset"

records = eventually(lambda: (root / "flow-terminal").read_text().splitlines()
                     if (root / "flow-terminal").exists() else None)
assert len(records) == 1
result = json.loads(records[0])
assert result["chunks"] == 64 and result["pressure"] > 0 and result["drains"] > 0
assert 16384 <= result["peak"] <= 32768, result
for marker in ("response-cancel", "upload-cancel"):
    reasons = (root / marker).read_text().splitlines()
    assert len(reasons) == 1 and reasons[0], (marker, reasons)
print(json.dumps({"flow": result, "response_reset": True, "upload_reset": True, "same_connection_recovered": True}))
