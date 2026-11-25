"""Independent protocol clients for a disposable loopback Runwire host."""
import concurrent.futures
import os
import socket
import ssl
import sys
import time

mode = sys.argv[1]
root = os.environ["LAB_WIRE_ROOT"]
address = ("127.0.0.1", 18785)

def connect():
    deadline = time.monotonic() + 30
    while True:
        s = socket.socket(socket.AF_UNIX if mode == "unix" else socket.AF_INET, socket.SOCK_STREAM)
        s.settimeout(3)
        try:
            s.connect(root + "/peer.sock" if mode == "unix" else address)
            return s
        except OSError:
            s.close()
            if time.monotonic() >= deadline:
                raise
            time.sleep(0.1)

def framed(worker):
    with connect() as s:
        with s.makefile("rb") as reader:
            for i in range(100):
                payload = f"{worker}-{i}".encode()
                s.sendall(payload[:2])
                s.sendall(payload[2:] + b"\n")
                assert reader.readline() == b"echo:" + payload + b"\n"
            s.sendall(b"a\nb\nc\n")
            assert [reader.readline() for _ in range(3)] == [b"echo:a\n", b"echo:b\n", b"echo:c\n"]

def udp(worker):
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
        s.settimeout(0.25)
        s.connect(address)
        for i in range(100):
            payload = bytes([worker, i]) + bytes(range(64))
            deadline = time.monotonic() + 5
            while True:
                try:
                    s.send(payload)
                    if s.recv(512) == payload:
                        break
                except (TimeoutError, ConnectionRefusedError):
                    pass
                assert time.monotonic() < deadline
        s.send(b"x" * 1024)
        try:
            s.recv(2048)
        except TimeoutError:
            pass
        else:
            raise AssertionError("Oversize UDP datagram reached echo handler")
        s.send(b"after")
        assert s.recv(512) == b"after"

def websocket(worker):
    from websockets.sync.client import connect as ws_connect
    from websockets.exceptions import InvalidStatus
    with ws_connect("ws://127.0.0.1:18785/ws", origin="https://lab.example", open_timeout=5, close_timeout=2) as ws:
        for i in range(50):
            text = f"{worker}-{i}"
            ws.send(text)
            assert ws.recv(timeout=3) == text
            payload = bytes([worker, i]) + bytes(range(64))
            ws.send(payload)
            assert ws.recv(timeout=3) == payload
        assert ws.ping(b"check").wait(3)
    if worker == 0:
        try:
            with ws_connect("ws://127.0.0.1:18785/ws", origin="https://foreign.example", open_timeout=5):
                raise AssertionError("Foreign origin upgraded")
        except InvalidStatus:
            pass

def http2(worker):
    from h2.config import H2Configuration
    from h2.connection import H2Connection
    from h2.events import DataReceived, ResponseReceived, StreamEnded, StreamReset
    ctx = ssl.create_default_context(cafile=root + "/cert.pem")
    ctx.set_alpn_protocols(["h2"])
    with connect() as raw, ctx.wrap_socket(raw, server_hostname="localhost") as s:
        assert s.selected_alpn_protocol() == "h2"
        s.settimeout(5)
        h = H2Connection(config=H2Configuration(client_side=True, header_encoding="utf-8"))
        h.initiate_connection()
        s.sendall(h.data_to_send())
        for batch in range(5):
            expected = {}
            received = {}
            statuses = {}
            for i in range(16):
                sid = h.get_next_available_stream_id()
                path = f"/worker/{worker}/{batch}/{i}"
                expected[sid] = ("2:" + path).encode()
                received[sid] = bytearray()
                h.send_headers(sid, [(":method", "GET"), (":scheme", "https"), (":authority", "localhost:18785"), (":path", path)], end_stream=True)
            s.sendall(h.data_to_send())
            complete = set()
            while len(complete) < len(expected):
                data = s.recv(65536)
                assert data, "HTTP/2 connection closed"
                for event in h.receive_data(data):
                    if isinstance(event, DataReceived):
                        received[event.stream_id].extend(event.data)
                        h.acknowledge_received_data(event.flow_controlled_length, event.stream_id)
                    elif isinstance(event, ResponseReceived):
                        statuses[event.stream_id] = dict(event.headers)[":status"]
                    elif isinstance(event, StreamEnded):
                        complete.add(event.stream_id)
                    elif isinstance(event, StreamReset):
                        raise AssertionError(f"Unexpected stream reset: {event.stream_id}")
                pending = h.data_to_send()
                if pending:
                    s.sendall(pending)
            assert all(statuses[sid] == "200" and received[sid] == body for sid, body in expected.items())

if mode in ("tcp", "unix", "websocket", "http2"):
    connect().close()
elif mode == "udp":
    time.sleep(1)
operation = {"tcp": framed, "unix": framed, "udp": udp, "websocket": websocket, "http2": http2}[mode]
with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
    for result in pool.map(operation, range(8)):
        assert result is None
if mode in ("tcp", "unix"):
    with connect() as s:
        s.sendall(b"x" * 8192 + b"\n")
        assert s.recv(1) == b"", "Oversize frame not rejected"
    framed(99)
if mode == "http2":
    ctx = ssl.create_default_context(cafile=root + "/cert.pem")
    ctx.set_alpn_protocols(["http/1.1"])
    with connect() as raw, ctx.wrap_socket(raw, server_hostname="localhost") as s:
        assert s.selected_alpn_protocol() == "http/1.1"
        s.sendall(b"GET /fallback HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
        data = b""
        while True:
            chunk = s.recv(4096)
            if not chunk:
                break
            data += chunk
        assert b"200 OK" in data and data.endswith(b"1.1:/fallback")
    try:
        with connect() as raw, ssl.create_default_context().wrap_socket(raw, server_hostname="localhost"):
            raise AssertionError("Untrusted peer certificate accepted")
    except ssl.SSLCertVerificationError:
        pass
print(f"{mode}: eight independent concurrent clients, exact protocol reconciliation and applicable negative boundaries passed")
