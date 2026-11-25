"""Disposable loopback PostgreSQL protocol fault injector.

Forward startup and all normal protocol traffic; on the first backend
CommandComplete(COMMIT), discard the acknowledgement and close the connection.
Never log credentials or query payloads.
"""
import asyncio
import sys
from pathlib import Path

root = Path(sys.argv[1])
dropped = False

async def handle(client_reader, client_writer):
    global dropped
    server_reader, server_writer = await asyncio.open_connection("127.0.0.1", 5432)

    async def requests():
        while data := await client_reader.read(65536):
            server_writer.write(data)
            await server_writer.drain()

    async def responses():
        global dropped
        while True:
            header = await server_reader.readexactly(5)
            length = int.from_bytes(header[1:], "big")
            if not 4 <= length <= 16 * 1024 * 1024:
                raise ValueError("Invalid fixture protocol frame length")
            payload = await server_reader.readexactly(length - 4)
            if not dropped and header[:1] == b"C" and payload == b"COMMIT\0":
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
