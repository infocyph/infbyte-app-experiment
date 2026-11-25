"""Opaque, loopback SQL Server transport gate. Never inspect encrypted payloads."""
import asyncio
import sys
from pathlib import Path

root = Path(sys.argv[1])
if int(sys.argv[2]) != 1433:
    raise ValueError("Unexpected fixture port")


async def handle(client_reader, client_writer):
    server_reader, server_writer = await asyncio.open_connection("127.0.0.1", 1433)
    armed = asyncio.Event()

    async def arm():
        if (root / "ack-dropped").exists():
            await asyncio.Future()
        while not (root / "arm").exists():
            await asyncio.sleep(0.01)
        armed.set()
        (root / "armed").touch()
        await asyncio.Future()

    async def requests():
        while data := await client_reader.read(65536):
            server_writer.write(data)
            await server_writer.drain()

    async def responses():
        while data := await server_reader.read(65536):
            if armed.is_set():
                (root / "response-held").touch()
                async with asyncio.timeout(18):
                    while not (root / "commit-observed").exists():
                        await asyncio.sleep(0.01)
                (root / "ack-dropped").touch()
                return
            client_writer.write(data)
            await client_writer.drain()

    tasks = [asyncio.create_task(arm()), asyncio.create_task(requests()), asyncio.create_task(responses())]
    try:
        await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
    finally:
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)
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
