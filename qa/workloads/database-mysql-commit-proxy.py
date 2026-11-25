"""Disposable MySQL-protocol commit acknowledgement fault.

Only forward traffic to the selected local laboratory SQL service.
Recognize COM_QUERY COMMIT and drop its real successful OK packet once.
No authentication or query payload is written to evidence.
"""
import asyncio
import sys
from pathlib import Path

root = Path(sys.argv[1])
port = int(sys.argv[2])
if port not in (3306, 3307):
    raise ValueError("Unexpected local fixture port")
dropped = False


async def packet(reader):
    header = await reader.readexactly(4)
    size = int.from_bytes(header[:3], "little")
    return header, await reader.readexactly(size)


async def handle(client_reader, client_writer):
    global dropped
    server_reader, server_writer = await asyncio.open_connection("127.0.0.1", port)
    awaiting_commit = False

    async def requests():
        nonlocal awaiting_commit
        while True:
            header, payload = await packet(client_reader)
            if header[3] == 0 and payload[:1] == b"\x03":
                awaiting_commit = payload[1:].strip().rstrip(b";").upper() == b"COMMIT"
            server_writer.write(header + payload)
            await server_writer.drain()

    async def responses():
        global dropped
        nonlocal awaiting_commit
        while True:
            header, payload = await packet(server_reader)
            if awaiting_commit and header[3] == 1:
                awaiting_commit = False
                if not dropped and payload[:1] == b"\x00":
                    dropped = True
                    (root / "ack-dropped").touch()
                    return
            client_writer.write(header + payload)
            await client_writer.drain()

    peers = [asyncio.create_task(requests()), asyncio.create_task(responses())]
    try:
        await asyncio.wait(peers, return_when=asyncio.FIRST_COMPLETED)
    finally:
        for task in peers:
            task.cancel()
        await asyncio.gather(*peers, return_exceptions=True)
        for writer in (client_writer, server_writer):
            writer.close()
        await asyncio.gather(client_writer.wait_closed(), server_writer.wait_closed(), return_exceptions=True)


async def main():
    server = await asyncio.start_server(handle, "127.0.0.1", 0)
    (root / "port.tmp").write_text(str(server.sockets[0].getsockname()[1]))
    (root / "port.tmp").rename(root / "port")
    async with server:
        await server.serve_forever()


asyncio.run(main())
