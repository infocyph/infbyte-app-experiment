"""Hold real QUIC receive credit to qualify the H3 aggregate response budget."""
import asyncio
import hashlib
import json
import os
from pathlib import Path
import sys

from aioquic.asyncio import connect, QuicConnectionProtocol
from aioquic.h3.connection import H3_ALPN, H3Connection
from aioquic.h3.events import DataReceived, HeadersReceived
from aioquic.quic.configuration import QuicConfiguration
from aioquic.quic.events import ConnectionTerminated, StreamReset

root = Path(os.environ["LAB_WIRE_ROOT"])
mode = sys.argv[1].removeprefix("http3-aggregate-")
CANCELLED = 0x10C


class Peer(QuicConnectionProtocol):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.http = H3Connection(self._quic)
        self.rows = {}
        self.released = set()
        self.cancelled = set()
        self.reset_events = {}
        # aioquic otherwise replenishes receive credit automatically. Restrict
        # only locally initiated bidirectional streams, not H3/QPACK control
        # streams. This test-only seam sends ordinary QUIC flow-control frames.
        original = self._quic._write_stream_limits

        def controlled(builder, space, stream):
            if stream.stream_id % 4 != 0 or stream.stream_id in self.released:
                original(builder, space, stream)

        self._quic._write_stream_limits = controlled

    def quic_event_received(self, event):
        if isinstance(event, ConnectionTerminated):
            for future, _, _, _ in self.rows.values():
                if not future.done():
                    future.set_exception(RuntimeError("Unexpected QUIC termination"))
        if isinstance(event, StreamReset):
            if event.stream_id in self.cancelled:
                self.reset_events[event.stream_id].set()
            elif event.stream_id in self.rows:
                future = self.rows[event.stream_id][0]
                if not future.done():
                    future.set_exception(RuntimeError("Unexpected response reset"))
        for message in self.http.handle_event(event):
            if not isinstance(message, (HeadersReceived, DataReceived)):
                continue
            if message.stream_id not in self.rows or message.stream_id in self.cancelled:
                continue
            future, headers, body, started = self.rows[message.stream_id]
            if isinstance(message, HeadersReceived):
                headers.update(message.headers)
            else:
                body.extend(message.data)
                if body:
                    started.set()
            if message.stream_ended and not future.done():
                future.set_result((headers, bytes(body)))

    def request(self, name):
        stream = self._quic.get_next_available_stream_id()
        self.rows[stream] = (asyncio.get_running_loop().create_future(), {},
                             bytearray(), asyncio.Event())
        self.reset_events[stream] = asyncio.Event()
        self.http.send_headers(stream, [(b":method", b"GET"), (b":scheme", b"https"),
                                        (b":authority", b"localhost:18785"),
                                        (b":path", ("/aggregate/" + name).encode())],
                               end_stream=True)
        self.transmit()
        return stream

    async def held(self, stream):
        future, headers, body, started = self.rows[stream]
        await asyncio.wait_for(started.wait(), 15)
        assert headers[b":status"] == b"200" and not future.done()
        assert 0 < len(body) <= 1024, len(body)

    async def drain(self, stream, name):
        self.released.add(stream)
        self.transmit()
        headers, body = await asyncio.wait_for(self.rows[stream][0], 20)
        expected = hashlib.sha256(name.encode()).digest() * 768
        assert headers[b":status"] == b"200" and body == expected, (name, len(body))
        assert (await record(name))["sha256"] == hashlib.sha256(expected).hexdigest()

    async def cancel(self, stream, name):
        self.cancelled.add(stream)
        self.rows[stream][0].cancel()
        self._quic.stop_stream(stream, CANCELLED)
        self.transmit()
        # Unlike H2, independent QUIC streams do not imply reset ordering.
        # Require server cancellation evidence before the replacement request.
        await marker("aggregate-cancel-" + name)


async def marker(name):
    for _ in range(300):
        path = root / name
        if path.exists() and path.stat().st_size:
            return path.read_text()
        await asyncio.sleep(0.05)
    raise AssertionError("Missing server marker: " + name)


async def record(name):
    for _ in range(300):
        try:
            return json.loads((root / ("aggregate-" + name)).read_text())
        except (FileNotFoundError, json.JSONDecodeError):
            await asyncio.sleep(0.05)
    raise AssertionError("Missing server write evidence: " + name)


def configuration():
    config = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN,
                               server_name="localhost", max_stream_data=1024,
                               max_data=1048576)
    config.load_verify_locations(str(root / "cert.pem"))
    return config


async def run():
    async with connect("127.0.0.1", 18785, configuration=configuration(), create_protocol=Peer) as peer:
        first = peer.request("first")
        await peer.held(first)
        one = await record("first")
        second = peer.request("second")
        await peer.held(second)
        two = await record("second")
        for row in (one, two):
            # HTTP/3 records encoded frame bytes, not solely body bytes.
            assert row["accepted"] and 22000 <= row["buffered"] <= 32768, row

        third = peer.request("rejected")
        rejection = await record("rejected")
        assert not rejection["accepted"], rejection
        headers, body = await asyncio.wait_for(peer.rows[third][0], 15)
        assert headers[b":status"] == b"200" and not body
        assert one["buffered"] + two["buffered"] + 24576 > 65536

        if mode == "isolation":
            async with connect("127.0.0.1", 18785, configuration=configuration(), create_protocol=Peer) as foreign:
                stream = foreign.request("foreign")
                assert (await record("foreign"))["accepted"]
                await foreign.drain(stream, "foreign")
            assert not peer.rows[first][0].done() and not peer.rows[second][0].done()
            await peer.drain(first, "first")
        elif mode == "drain":
            await peer.drain(first, "first")
        elif mode == "reset":
            await peer.cancel(first, "first")
        else:
            raise AssertionError("Unknown aggregate fixture mode")

        replacement = peer.request("replacement")
        assert (await record("replacement"))["accepted"], "Aggregate capacity did not recover"
        await peer.drain(replacement, "replacement")
        await peer.drain(second, "second")
        final = peer.request("final")
        assert (await record("final"))["accepted"]
        await peer.drain(final, "final")
        print(json.dumps({"mode": mode, "first": one, "second": two,
                          "third": rejection, "replacement_verified": True}))


if __name__ == "__main__":
    asyncio.run(asyncio.wait_for(run(), 100))
