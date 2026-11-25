"""Fixed-destination loopback CONNECT fixture; no arbitrary proxy destinations."""
import asyncio
import base64
import hmac
import json
import sys
from pathlib import Path

root = Path(sys.argv[1])
target_port = int(sys.argv[2])
listen_port = int(sys.argv[3])
counts = {"accepted": 0, "denied": 0}
auth = b"Basic " + base64.b64encode(b"fixture:fixture-only-password")


def persist():
    path = root / "proxy-counts.tmp"
    path.write_text(json.dumps(counts))
    path.replace(root / "proxy-counts.json")


async def handle(reader, writer):
    upstream_writer = None
    tasks = []
    try:
        async with asyncio.timeout(10):
            raw = await reader.readuntil(b"\r\n\r\n")
        lines = raw.split(b"\r\n")
        parts = lines[0].split()
        headers = {}
        for line in lines[1:]:
            if b":" in line:
                name, value = line.split(b":", 1)
                headers[name.lower()] = value.strip()
        if len(parts) != 3 or parts[:2] != [b"CONNECT", f"localhost:{target_port}".encode()]:
            writer.write(b"HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n")
            await writer.drain()
            return
        if not hmac.compare_digest(headers.get(b"proxy-authorization", b""), auth):
            counts["denied"] += 1
            persist()
            writer.write(b'HTTP/1.1 407 Proxy Authentication Required\r\nProxy-Authenticate: Basic realm="fixture"\r\nContent-Length: 0\r\nConnection: close\r\n\r\n')
            await writer.drain()
            return
        upstream_reader, upstream_writer = await asyncio.open_connection("127.0.0.1", target_port)
        counts["accepted"] += 1
        persist()
        writer.write(b"HTTP/1.1 200 Connection Established\r\n\r\n")
        await writer.drain()

        async def copy(source, destination):
            while data := await source.read(65536):
                destination.write(data)
                await destination.drain()

        tasks = [asyncio.create_task(copy(reader, upstream_writer)), asyncio.create_task(copy(upstream_reader, writer))]
        async with asyncio.timeout(20):
            await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
    finally:
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)
        writers = [item for item in (writer, upstream_writer) if item is not None]
        for item in writers:
            item.close()
        await asyncio.gather(*(item.wait_closed() for item in writers), return_exceptions=True)


async def main():
    server = await asyncio.start_server(handle, "127.0.0.1", listen_port, limit=16384)
    persist()
    async with server:
        await server.serve_forever()


asyncio.run(main())
