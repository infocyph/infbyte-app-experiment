"""Loopback AMQP 0-9-1 settlement fault relay; never log payloads."""
import asyncio
import struct
import sys
from pathlib import Path

root = Path(sys.argv[1])


async def frame(reader):
    header = await reader.readexactly(7)
    kind, channel, size = struct.unpack("!BHI", header)
    if size > 16 * 1024 * 1024:
        raise ValueError("AMQP fixture frame too large")
    payload = await reader.readexactly(size)
    end = await reader.readexactly(1)
    if end != b"\xce":
        raise ValueError("Invalid AMQP frame terminator")
    method = struct.unpack("!HH", payload[:4]) if kind == 1 and size >= 4 else None
    return header + payload + end, channel, method


async def handle(client_reader, client_writer):
    server_reader, server_writer = await asyncio.open_connection("127.0.0.1", 15672)
    phase = (root / "phase").read_text().strip()
    ack_channel = None

    async def requests():
        nonlocal ack_channel
        protocol = await client_reader.readexactly(8)
        if protocol != b"AMQP\x00\x00\x09\x01":
            raise ValueError("Unexpected AMQP protocol header")
        server_writer.write(protocol)
        await server_writer.drain()
        while True:
            raw, channel, method = await frame(client_reader)
            if method == (60, 80):
                if phase == "before" and not (root / phase).exists():
                    (root / phase).write_text("basic.ack withheld\n")
                    return
                if phase == "after" and not (root / phase).exists():
                    ack_channel = channel
            server_writer.write(raw)
            await server_writer.drain()

    async def responses():
        while True:
            raw, channel, method = await frame(server_reader)
            if phase == "after" and ack_channel == channel and method == (50, 11):
                (root / phase).write_text("queue.declare-ok withheld after basic.ack\n")
                return
            client_writer.write(raw)
            await client_writer.drain()

    tasks = [asyncio.create_task(requests()), asyncio.create_task(responses())]
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
    server = await asyncio.start_server(handle, "127.0.0.1", 15673)
    (root / "ready").touch()
    async with server:
        await server.serve_forever()


asyncio.run(main())
