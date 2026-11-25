"""Exercise actual per-worker multiplexed admission independently of client limits."""
import asyncio
import os
from pathlib import Path
import socket
import ssl
import sys
import time

from h2.config import H2Configuration
from h2.connection import H2Connection
from h2.events import DataReceived, ResponseReceived, StreamEnded, StreamReset
from aioquic.asyncio import connect
from aioquic.h3.connection import H3_ALPN
from aioquic.quic.configuration import QuicConfiguration
import importlib
Peer = importlib.import_module('wire-http3-flow').Peer

root = Path(os.environ["LAB_WIRE_ROOT"])


def records(name):
    path = root / name
    return path.read_text().splitlines() if path.exists() else []


def until(name, value):
    deadline = time.monotonic() + 10
    while time.monotonic() < deadline:
        if value in records(name):
            return
        time.sleep(0.02)
    raise AssertionError(f"Missing {name}: {value}")


async def async_until(name, value):
    for _ in range(500):
        if value in records(name):
            return
        await asyncio.sleep(0.02)
    raise AssertionError(f"Missing {name}: {value}")


def check_records():
    admitted = records("admitted")
    for wave in range(8):
        for path in (f"/hold/{wave}/a", f"/hold/{wave}/b", f"/recovered/{wave}/a", f"/recovered/{wave}/b"):
            assert admitted.count(path) == 1, (path, admitted)
        assert f"/denied/{wave}" not in admitted
    assert sorted(records("cancelled")) == sorted(f"/hold/{wave}/b" for wave in range(8))


def http2():
    context = ssl.create_default_context(cafile=str(root / "cert.pem"))
    context.set_alpn_protocols(["h2"])
    for attempt in range(100):
        try:
            raw = socket.create_connection(("127.0.0.1", 18785), timeout=2)
            break
        except OSError:
            time.sleep(0.1)
    else:
        raise AssertionError("H2 peer unavailable")
    with raw, context.wrap_socket(raw, server_hostname="localhost") as conn:
        assert conn.selected_alpn_protocol() == "h2"
        conn.settimeout(10)
        h = H2Connection(config=H2Configuration(client_side=True, header_encoding="utf-8"))
        h.initiate_connection()
        headers, bodies, ended = {}, {}, set()
        def flush():
            data = h.data_to_send()
            if data:
                conn.sendall(data)
        def request(path, hold=False):
            sid = h.get_next_available_stream_id()
            bodies[sid] = bytearray()
            h.send_headers(sid, [(":method", "POST" if hold else "GET"), (":scheme", "https"),
                                 (":authority", "localhost:18785"), (":path", path)], end_stream=not hold)
            flush()
            return sid
        def complete(sid):
            while sid not in ended:
                data = conn.recv(65536)
                assert data, "Connection unexpectedly closed"
                for event in h.receive_data(data):
                    if isinstance(event, ResponseReceived):
                        headers[event.stream_id] = dict(event.headers)
                    elif isinstance(event, DataReceived):
                        bodies[event.stream_id].extend(event.data)
                        h.acknowledge_received_data(event.flow_controlled_length, event.stream_id)
                    elif isinstance(event, StreamEnded):
                        ended.add(event.stream_id)
                    elif isinstance(event, StreamReset):
                        raise AssertionError(f"Unexpected reset: {event.stream_id}")
                flush()
            return headers[sid], bytes(bodies[sid])
        flush()
        for wave in range(8):
            a_path, b_path = f"/hold/{wave}/a", f"/hold/{wave}/b"
            a, b = request(a_path, True), request(b_path, True)
            until("admitted", a_path)
            until("admitted", b_path)
            denied, body = complete(request(f"/denied/{wave}"))
            assert denied[":status"] == "503" and denied["retry-after"] == "3" and not body
            h.end_stream(a)
            flush()
            assert complete(a)[1] == f"2:{a_path}".encode()
            assert complete(request(f"/recovered/{wave}/a"))[1] == f"2:/recovered/{wave}/a".encode()
            h.reset_stream(b, error_code=8)
            flush()
            until("cancelled", b_path)
            assert complete(request(f"/recovered/{wave}/b"))[1] == f"2:/recovered/{wave}/b".encode()


async def http3():
    config = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN, server_name="localhost")
    config.load_verify_locations(str(root / "cert.pem"))
    async with connect("127.0.0.1", 18785, configuration=config, create_protocol=Peer) as peer:
        async def complete(row):
            return await asyncio.wait_for(row[0], 10)
        for wave in range(8):
            a_path, b_path = f"/hold/{wave}/a", f"/hold/{wave}/b"
            a, ar = peer.request(a_path, "POST", False)
            b, _ = peer.request(b_path, "POST", False)
            await async_until("admitted", a_path)
            await async_until("admitted", b_path)
            _, denied = peer.request(f"/denied/{wave}")
            headers, body = await complete(denied)
            assert headers[b":status"] == b"503" and headers[b"retry-after"] == b"3" and not body
            peer.http.send_data(a, b"", end_stream=True)
            peer.transmit()
            assert (await complete(ar))[1] == f"3:{a_path}".encode()
            _, recovered = peer.request(f"/recovered/{wave}/a")
            assert (await complete(recovered))[1] == f"3:/recovered/{wave}/a".encode()
            peer.cancel(b, upload=True)
            await async_until("cancelled", b_path)
            _, recovered = peer.request(f"/recovered/{wave}/b")
            assert (await complete(recovered))[1] == f"3:/recovered/{wave}/b".encode()


if sys.argv[1] == "http2-admission":
    http2()
else:
    asyncio.run(asyncio.wait_for(http3(), 120))
check_records()
print("Eight multiplexed admission, overload and release waves reconciled")
