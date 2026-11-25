"""Native QUIC response pressure and independent directional cancellation."""
import asyncio
import json
import os
from pathlib import Path

from aioquic.asyncio import connect, QuicConnectionProtocol
from aioquic.h3.connection import H3_ALPN, H3Connection
from aioquic.h3.events import DataReceived, HeadersReceived
from aioquic.quic.configuration import QuicConfiguration
from aioquic.quic.events import ConnectionTerminated, StreamReset

root = Path(os.environ["LAB_WIRE_ROOT"])
CANCELLED = 0x10C


class Peer(QuicConnectionProtocol):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.http = H3Connection(self._quic)
        self.pending = {}
        self.cancelled = set()

    def quic_event_received(self, event):
        if isinstance(event, ConnectionTerminated):
            for future, *_ in self.pending.values():
                if not future.done():
                    future.set_exception(RuntimeError("Unexpected QUIC connection termination"))
        if isinstance(event, StreamReset) and event.stream_id not in self.cancelled:
            row = self.pending.get(event.stream_id)
            if row and not row[0].done():
                row[0].set_exception(RuntimeError("Unexpected QUIC stream reset"))
        for message in self.http.handle_event(event):
            if not isinstance(message, (HeadersReceived, DataReceived)):
                continue
            row = self.pending.get(message.stream_id)
            if row is None or message.stream_id in self.cancelled:
                continue
            future, headers, body, started = row
            if isinstance(message, HeadersReceived):
                headers.update(message.headers)
            else:
                body.extend(message.data)
                if body:
                    started.set()
            if message.stream_ended and not future.done():
                future.set_result((headers, bytes(body)))

    def request(self, path, method="GET", complete=True):
        stream = self._quic.get_next_available_stream_id()
        row = (asyncio.get_running_loop().create_future(), {}, bytearray(), asyncio.Event())
        self.pending[stream] = row
        self.http.send_headers(stream, [(b":method", method.encode()), (b":scheme", b"https"),
                                        (b":authority", b"localhost:18785"), (b":path", path.encode())],
                               end_stream=complete)
        self.transmit()
        return stream, row

    def cancel(self, stream, upload=False):
        self.cancelled.add(stream)
        if upload:
            self._quic.reset_stream(stream, CANCELLED)
        else:
            self._quic.stop_stream(stream, CANCELLED)
        self.pending[stream][0].cancel()
        self.transmit()


async def marker(name):
    for _ in range(300):
        path = root / name
        if path.exists() and path.stat().st_size:
            return path.read_text().splitlines()
        await asyncio.sleep(0.05)
    raise AssertionError(f"Missing server marker: {name}")


async def run():
    config = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN, server_name="localhost",
                               max_stream_data=8192, max_data=65536)
    config.load_verify_locations(str(root / "cert.pem"))
    async with connect("127.0.0.1", 18785, configuration=config, create_protocol=Peer) as peer:
        _, streamed = peer.request("/flow-stream")
        _, sibling = peer.request("/sibling")
        headers, body = await asyncio.wait_for(sibling[0], 15)
        assert headers[b":status"] == b"200" and body == b"3:/sibling"
        headers, body = await asyncio.wait_for(streamed[0], 30)
        assert headers[b":status"] == b"200"
        assert body == b"".join(bytes([i]) * 4096 for i in range(64))
        records = await marker("flow-terminal")
        assert len(records) == 1
        result = json.loads(records[0])
        assert result["chunks"] == 64 and result["pressure"] > 0 and result["drains"] > 0
        assert 16384 <= result["peak"] <= 32768, result

        cancelled, partial = peer.request("/flow-cancel")
        await asyncio.wait_for(partial[3].wait(), 15)
        assert len(partial[2]) > 0 and not partial[0].done()
        peer.cancel(cancelled)
        reasons = await marker("response-cancel")
        assert len(reasons) == 1 and reasons[0]

        upload, _ = peer.request("/flow-upload", "POST", False)
        peer.http.send_data(upload, b"partial", end_stream=False)
        peer.transmit()
        await marker("upload-ready")
        peer.cancel(upload, upload=True)
        reasons = await marker("upload-cancel")
        assert len(reasons) == 1 and reasons[0]
        _, recovered = peer.request("/after-reset")
        headers, body = await asyncio.wait_for(recovered[0], 15)
        assert headers[b":status"] == b"200" and body == b"3:/after-reset"
    print(json.dumps({"flow": result, "stop_sending": True, "upload_reset": True,
                      "same_connection_recovered": True}))


if __name__ == "__main__":
    asyncio.run(asyncio.wait_for(run(), 90))
