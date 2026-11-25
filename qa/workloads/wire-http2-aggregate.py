"""Real H2 stream-window exhaustion against a shared connection response budget."""
import hashlib
import json
import os
from pathlib import Path
import socket
import ssl
import sys
import time

from h2.config import H2Configuration
from h2.connection import H2Connection
from h2.events import DataReceived, ResponseReceived, StreamEnded, StreamReset, SettingsAcknowledged
from h2.settings import SettingCodes

root = Path(os.environ["LAB_WIRE_ROOT"])
context = ssl.create_default_context(cafile=str(root / "cert.pem"))
context.set_alpn_protocols(["h2"])
mode = sys.argv[1].removeprefix("http2-aggregate-")


def record(name):
    deadline = time.monotonic() + 10
    path = root / ("aggregate-" + name)
    while time.monotonic() < deadline:
        try:
            return json.loads(path.read_text())
        except (FileNotFoundError, json.JSONDecodeError):
            time.sleep(0.02)
    raise AssertionError(f"Missing independent server evidence for {name}")


class Peer:
    def __enter__(self):
        deadline = time.monotonic() + 15
        while True:
            try:
                raw = socket.create_connection(("127.0.0.1", 18785), timeout=2)
                break
            except OSError:
                if time.monotonic() >= deadline:
                    raise
                time.sleep(0.05)
        self.sock = context.wrap_socket(raw, server_hostname="localhost")
        assert self.sock.selected_alpn_protocol() == "h2"
        self.sock.settimeout(5)
        self.h = H2Connection(config=H2Configuration(client_side=True, header_encoding="utf-8"))
        self.h.initiate_connection()
        self.h.update_settings({SettingCodes.INITIAL_WINDOW_SIZE: 0})
        self.bodies, self.statuses, self.ended = {}, {}, set()
        self.settings_acks = 0
        self.flush()
        self.until(lambda: self.settings_acks >= 2)
        return self

    def __exit__(self, *args):
        self.sock.close()

    def flush(self):
        data = self.h.data_to_send()
        if data:
            self.sock.sendall(data)

    def receive(self):
        data = self.sock.recv(65536)
        assert data, "Connection closed unexpectedly"
        for event in self.h.receive_data(data):
            if isinstance(event, SettingsAcknowledged):
                self.settings_acks += 1
            elif isinstance(event, DataReceived):
                self.bodies[event.stream_id].extend(event.data)
                self.h.acknowledge_received_data(event.flow_controlled_length, event.stream_id)
            elif isinstance(event, ResponseReceived):
                self.statuses[event.stream_id] = dict(event.headers)[":status"]
            elif isinstance(event, StreamEnded):
                self.ended.add(event.stream_id)
            elif isinstance(event, StreamReset):
                raise AssertionError(f"Unexpected peer reset: {event.stream_id}")
        self.flush()

    def until(self, predicate):
        deadline = time.monotonic() + 15
        while not predicate():
            assert time.monotonic() < deadline, "Timed out waiting for protocol evidence"
            self.receive()

    def request(self, name):
        sid = self.h.get_next_available_stream_id()
        self.bodies[sid] = bytearray()
        self.h.send_headers(sid, [(":method", "GET"), (":scheme", "https"),
                                  (":authority", "localhost:18785"), (":path", "/aggregate/" + name)],
                            end_stream=True)
        self.flush()
        self.until(lambda: sid in self.statuses)
        assert self.statuses[sid] == "200"
        return sid

    def drain(self, sid, name):
        self.h.increment_flow_control_window(32768, stream_id=sid)
        self.flush()
        self.until(lambda: sid in self.ended)
        expected = hashlib.sha256(name.encode()).digest() * 768
        assert self.bodies[sid] == expected, (name, len(self.bodies[sid]))
        assert record(name)["sha256"] == hashlib.sha256(expected).hexdigest()


with Peer() as peer:
    first = peer.request("first")
    second = peer.request("second")
    for name, sid in (("first", first), ("second", second)):
        result = record(name)
        assert result["accepted"] and result["buffered"] == 24576, result
        assert not peer.bodies[sid] and sid not in peer.ended
    third = peer.request("rejected")
    result = record("rejected")
    assert not result["accepted"] and result["buffered"] == 0, result
    # Each individual body fits 32 KiB, but three do not fit the 64 KiB connection budget.
    peer.until(lambda: third in peer.ended)
    assert peer.bodies[third] == b""

    if mode == "isolation":
        with Peer() as foreign:
            independent = foreign.request("foreign")
            assert record("foreign")["accepted"]
            foreign.drain(independent, "foreign")
        assert not peer.bodies[first] and not peer.bodies[second]
        peer.drain(first, "first")
    elif mode == "drain":
        peer.drain(first, "first")
    elif mode == "reset":
        peer.h.reset_stream(first, error_code=8)
        peer.flush()
        # Subsequent stream headers are ordered after the reset on the same connection.
    else:
        raise AssertionError("Unknown aggregate fixture mode")
    recovery = peer.request("replacement")
    assert record("replacement")["accepted"], "Connection budget did not release"
    peer.drain(recovery, "replacement")
    peer.drain(second, "second")
    final = peer.request("final")
    assert record("final")["accepted"]
    peer.drain(final, "final")

print(json.dumps({"mode": mode, "per_stream_limit": 32768, "aggregate_limit": 65536,
                  "held_bytes": 49152, "third_rejected": True, "replacement_verified": True}))
