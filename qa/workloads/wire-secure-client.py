"""Independent HTTP/3 and mutual-TLS acceptance clients."""
import asyncio
import concurrent.futures
import os
import socket
import ssl
import sys
import time

ROOT = os.environ["LAB_WIRE_ROOT"]
PORT = 18785

def mtls_exchange(identity, target):
    context = ssl.create_default_context(cafile=ROOT + "/ca.pem")
    context.set_alpn_protocols(["http/1.1"])
    if identity:
        context.load_cert_chain(ROOT + "/" + identity + ".pem", ROOT + "/" + identity + ".key")
    with socket.create_connection(("127.0.0.1", PORT), timeout=4) as raw:
        with context.wrap_socket(raw, server_hostname="localhost") as conn:
            conn.sendall(f"GET {target} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n".encode())
            result = bytearray()
            while chunk := conn.recv(4096):
                result.extend(chunk)
    return bytes(result)

def mtls():
    deadline = time.monotonic() + 30
    while True:
        try:
            assert b"200 OK" in mtls_exchange("client", "/ready")
            break
        except ConnectionRefusedError:
            if time.monotonic() > deadline:
                raise
            time.sleep(0.1)
    for identity in (None, "foreign"):
        try:
            response = mtls_exchange(identity, "/denied")
        except (ssl.SSLError, ConnectionResetError, BrokenPipeError):
            continue
        assert b"200 OK" not in response, "Unauthenticated TLS request dispatched"
    def worker(index):
        for i in range(20):
            target = f"/worker/{index}/{i}"
            response = mtls_exchange("client", target)
            assert b"200 OK" in response and response.endswith(("1.1:" + target).encode())
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        list(pool.map(worker, range(8)))
    print("mTLS: 160 authenticated requests, missing and foreign client certificate rejection")

async def h3():
    from aioquic.asyncio import connect, QuicConnectionProtocol
    from aioquic.h3.connection import H3_ALPN, H3Connection
    from aioquic.h3.events import DataReceived, HeadersReceived
    from aioquic.quic.configuration import QuicConfiguration
    from aioquic.quic.events import ConnectionTerminated, StreamReset

    class Peer(QuicConnectionProtocol):
        def __init__(self, *args, **kwargs):
            super().__init__(*args, **kwargs)
            self.http = H3Connection(self._quic)
            self.pending = {}

        def quic_event_received(self, event):
            if isinstance(event, (ConnectionTerminated, StreamReset)):
                for future, _, _ in self.pending.values():
                    if not future.done():
                        future.set_exception(RuntimeError("Unexpected QUIC termination/reset"))
            for message in self.http.handle_event(event):
                if not isinstance(message, (HeadersReceived, DataReceived)):
                    continue
                if message.stream_id not in self.pending:
                    continue
                future, headers, body = self.pending[message.stream_id]
                if isinstance(message, HeadersReceived):
                    headers.update(message.headers)
                else:
                    body.extend(message.data)
                if message.stream_ended and not future.done():
                    future.set_result((headers, bytes(body)))

        async def get(self, path):
            stream = self._quic.get_next_available_stream_id()
            future = asyncio.get_running_loop().create_future()
            self.pending[stream] = (future, {}, bytearray())
            try:
                self.http.send_headers(stream, [(b":method", b"GET"), (b":scheme", b"https"), (b":authority", b"localhost:18785"), (b":path", path.encode())], end_stream=True)
                self.transmit()
                return await asyncio.wait_for(future, 10)
            finally:
                self.pending.pop(stream, None)

    async def worker(index):
        config = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN, server_name="localhost")
        config.load_verify_locations(ROOT + "/cert.pem")
        async with connect("127.0.0.1", PORT, configuration=config, create_protocol=Peer) as client:
            for batch in range(5):
                paths = [f"/worker/{index}/{batch}/{i}" for i in range(16)]
                responses = await asyncio.gather(*(client.get(path) for path in paths))
                for path, (headers, body) in zip(paths, responses):
                    assert headers[b":status"] == b"200" and body == ("3:" + path).encode()
    # A failed handshake is a test failure, never a fall back to TCP.
    await asyncio.wait_for(asyncio.gather(*(worker(i) for i in range(8))), 90)
    print("HTTP/3: 640 multiplexed requests on 8 QUIC connections, trusted TLS and exact body reconciliation")

if sys.argv[1] == "mtls":
    mtls()
elif sys.argv[1] == "http3":
    asyncio.run(h3())
else:
    raise ValueError("Unsupported secure protocol")
