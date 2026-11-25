"""Native HTTP framing and supervisor lifecycle, using independent clients."""
import concurrent.futures
import hashlib
import http.client
import json
import os
import socket
import stat
import sys
import time

ADDRESS = ("127.0.0.1", 18786)
CONTROL = os.environ["LAB_BOUNDARY_ROOT"] + "/control.sock"

def eventually(operation, seconds=30):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        try:
            result = operation()
            if result:
                return result
        except (OSError, http.client.HTTPException):
            pass
        time.sleep(0.05)
    raise AssertionError("Readiness or state transition timed out")

def exchange(target, body=None):
    connection = http.client.HTTPConnection(*ADDRESS, timeout=5)
    try:
        connection.request("GET" if body is None else "POST", target, body=body, headers={"Connection": "close"})
        response = connection.getresponse()
        payload = response.read()
        assert response.status == 200, response.status
        result = json.loads(payload)
        assert result["target"] == target
        assert result["bytes"] == len(body or b"")
        assert result["digest"] == hashlib.sha256(body or b"").hexdigest()
        return result
    finally:
        connection.close()

def control(payload):
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as conn:
        conn.settimeout(3)
        conn.connect(CONTROL)
        conn.sendall(json.dumps({"version": 1, **payload}).encode() + b"\n")
        with conn.makefile("rb") as reader:
            return json.loads(reader.readline())

def status():
    result = control({"action": "status"})
    assert result["ok"]
    return result["data"]["status"]

def current_pids(snapshot):
    return {item["pid"] for item in snapshot["workers"] if item["current"]}

def supervisor():
    first = eventually(lambda: (s if (s := status())["ready_worker_count"] == 2 else None))
    runtime_id = first["runtime_id"]
    old = current_pids(first)
    assert len(old) == 2
    assert stat.S_IMODE(os.stat(CONTROL).st_mode) == 0o600
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as conn:
        conn.settimeout(3)
        conn.connect(CONTROL)
        conn.sendall(b"x" * 600 + b"\n")
        assert conn.recv(1) == b""
    stale = control({"action": "reload", "runtime_id": "0" * len(runtime_id)})
    assert not stale["ok"] and stale["error"]["code"] == "runtime_mismatch"
    assert status()["generation"] == first["generation"]
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        pids = {r["pid"] for r in pool.map(lambda i: exchange(f"/request/{i}"), range(120))}
    assert len(pids) > 2, "Request-count recycle did not occur"
    stable = eventually(lambda: (s if (s := status())["ready_worker_count"] == 2 else None))
    worker = next(w for w in stable["workers"] if w["current"])
    result = control({"action": "recycle", "runtime_id": runtime_id, "group": worker["group"], "slot": worker["slot"]})
    assert result["ok"] and result["data"]["accepted"]
    replaced = eventually(lambda: (s if (s := status())["ready_worker_count"] == 2 and worker["pid"] not in current_pids(s) else None))
    before = current_pids(replaced)
    result = control({"action": "reload", "runtime_id": runtime_id})
    assert result["ok"] and result["data"]["accepted"]
    ready = eventually(lambda: (s if (s := status())["generation"] > replaced["generation"] and s["generation_ready"] and s["ready_worker_count"] == 2 and not s["reloading"] else None))
    assert current_pids(ready).isdisjoint(before)
    assert exchange("/after-reload")["pid"] in current_pids(ready)
    stop = control({"action": "stop", "runtime_id": runtime_id, "force": False})
    assert stop["ok"] and stop["data"]["accepted"]
    print("Real supervisor: socket permissions, oversized/stale control, request recycling, slot replacement, generation reload and graceful stop")

def raw(wire):
    with socket.create_connection(ADDRESS, timeout=3) as conn:
        conn.settimeout(4)
        conn.sendall(wire)
        data = bytearray()
        while True:
            chunk = conn.recv(8192)
            if not chunk:
                break
            data.extend(chunk)
        return bytes(data)

def framing():
    eventually(lambda: exchange("/ready"))
    payload = bytes(range(256)) * 240
    for i in range(8):
        exchange(f"/body/{i}", payload)
    chunks = b"5\r\nhello\r\n6\r\n world\r\n0\r\nX-Final: yes\r\n\r\n"
    valid = raw(b"POST /chunked HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n" + chunks)
    headers, body = valid.split(b"\r\n\r\n", 1)
    assert b"200 OK" in headers and json.loads(body)["digest"] == hashlib.sha256(b"hello world").hexdigest()
    cases = [
        b"POST /denied HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n0\r\n\r\n",
        b"POST /denied HTTP/1.1\r\nHost: localhost\r\nContent-Length: 4\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello",
        b"POST /denied HTTP/1.1\r\nHost: localhost\r\nContent-Length: 65537\r\nConnection: close\r\n\r\n",
        b"GET /denied HTTP/1.1\r\nHost: localhost\r\nX-Large: " + b"x" * 2000 + b"\r\nConnection: close\r\n\r\n",
        b"GET /" + b"x" * 2000 + b" HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n",
    ]
    for wire in cases:
        try:
            response = raw(wire)
        except ConnectionResetError:
            continue
        assert b"200 OK" not in response and b'"target":"/denied"' not in response
    with socket.create_connection(ADDRESS, timeout=3) as conn:
        conn.settimeout(5)
        conn.sendall(b"GET /slow HTTP/1.1\r\nHost: localhost\r\n")
        result = conn.recv(4096)
        assert b"200 OK" not in result
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        list(pool.map(lambda i: exchange(f"/recovered/{i}", payload), range(40)))
    print("HTTP/1: chunked and 60 KiB body digest, conflicting framing, resource limits, slow headers and recovery")

def backpressure():
    eventually(lambda: exchange("/ready"))
    root = os.environ["LAB_BOUNDARY_ROOT"]
    conn = http.client.HTTPConnection(*ADDRESS, timeout=15)
    try:
        conn.request("GET", "/pressured-stream", headers={"Connection": "close"})
        response = conn.getresponse()
        assert response.status == 200
        assert int(response.getheader("Content-Length")) == 8192 * 128
        for index in range(128):
            assert response.read(8192) == bytes([index]) * 8192, index
            time.sleep(0.002)
        assert response.read() == b""
    finally:
        conn.close()
    terminal = os.path.join(root, "terminal.log")
    eventually(lambda: os.path.exists(terminal))
    with open(terminal, encoding="utf-8") as stream:
        records = [json.loads(line) for line in stream]
    assert len(records) == 1, records
    record = records[0]
    assert record["chunks"] == 128
    assert record["pressure"] > 0 and record["drains"] > 0, record
    assert 4096 <= record["peak"] <= 16384, record
    with socket.create_connection(ADDRESS, timeout=5) as upload:
        upload.sendall(b"POST /aborted-upload HTTP/1.1\r\nHost: localhost\r\nContent-Length: 1000\r\n\r\nshort")
        eventually(lambda: os.path.exists(os.path.join(root, "upload-ready")))
        upload.shutdown(socket.SHUT_RDWR)
    cancelled = os.path.join(root, "cancelled.log")
    eventually(lambda: os.path.exists(cancelled))
    with open(cancelled, encoding="utf-8") as stream:
        reasons = stream.read().splitlines()
    assert len(reasons) == 1 and reasons[0], reasons
    for index in range(8):
        exchange(f"/after-abort/{index}", b"healthy")
    with open(terminal, encoding="utf-8") as stream:
        assert len(stream.readlines()) == 1
    print(json.dumps({"stream": record, "cancelled": reasons, "recovery_requests": 8}))



def quota_records(name):
    path = os.path.join(os.environ["LAB_BOUNDARY_ROOT"], name)
    if not os.path.exists(path):
        return []
    with open(path, encoding="utf-8") as stream:
        return stream.read().splitlines()


def held_upload(target):
    connection = socket.create_connection(ADDRESS, timeout=3)
    connection.settimeout(5)
    connection.sendall(f"POST {target} HTTP/1.1\r\nHost: localhost\r\nContent-Length: 6\r\nConnection: close\r\n\r\n".encode() + b"ab")
    eventually(lambda: target in quota_records("admitted.log"), seconds=5)
    return connection


def read_response(connection):
    response = http.client.HTTPResponse(connection)
    response.begin()
    body = response.read()
    return response.status, dict(response.getheaders()), body


def admission():
    eventually(lambda: exchange("/ready"))
    for wave in range(8):
        first = f"/quota-hold/{wave}/first"
        second = f"/quota-hold/{wave}/second"
        rejected = f"/quota-rejected/{wave}"
        with held_upload(first) as a, held_upload(second) as b:
            conn = http.client.HTTPConnection(*ADDRESS, timeout=3)
            try:
                conn.request("GET", rejected)
                response = conn.getresponse()
                assert response.status == 503 and response.getheader("Retry-After") == "2"
                assert response.read() == b""
            finally:
                conn.close()
            assert rejected not in quota_records("admitted.log")
            a.sendall(b"cdef")
            status, _, body = read_response(a)
            assert status == 200 and json.loads(body)["digest"] == hashlib.sha256(b"abcdef").hexdigest()
            # One terminal response frees capacity while the other remains active.
            exchange(f"/quota-recovered/{wave}")
            b.shutdown(socket.SHUT_RDWR)
        eventually(lambda: second in quota_records("quota-cancel.log"), seconds=5)
        exchange(f"/quota-after-cancel/{wave}")
    records = quota_records("admitted.log")
    assert all(not row.startswith("/quota-rejected/") for row in records), records
    assert all(records.count(f"/quota-hold/{wave}/first") == 1 and records.count(f"/quota-hold/{wave}/second") == 1 for wave in range(8))
    cancelled = quota_records("quota-cancel.log")
    assert sorted(cancelled) == sorted(f"/quota-hold/{wave}/second" for wave in range(8)), cancelled
    print("8 full-capacity HTTP/1 waves: real 503/Retry-After, no handler entry, successful terminal and cancellation release")


def connection_quotas():
    eventually(lambda: exchange("/ready"))
    first = http.client.HTTPConnection(*ADDRESS, timeout=3)
    second = http.client.HTTPConnection(*ADDRESS, timeout=3)
    third = None

    def send(conn, path):
        conn.request("GET", path)
        reply = conn.getresponse()
        assert reply.status == 200 and json.loads(reply.read())["target"] == path
        return reply

    try:
        send(first, "/quota-connected/first")
        send(second, "/quota-connected/second")
        assert first.sock is not None and second.sock is not None
        third = socket.create_connection(ADDRESS, timeout=2)
        third.settimeout(0.4)
        third.sendall(b"GET /quota-connected/overflow HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
        try:
            data = third.recv(4096)
            assert b"200 OK" not in data
        except (TimeoutError, ConnectionResetError):
            pass
        assert "/quota-connected/overflow" not in quota_records("admitted.log"), "Connection cap allowed a third active connection"
        # Close the queued or refused client before releasing a slot.
        third.close()
        third = None
        second.close()
        eventually(lambda: exchange("/quota-after-release"), seconds=5)
        send(first, "/quota-keepalive/2")
        final = send(first, "/quota-keepalive/3")
        assert final.will_close or first.sock is None, "Keepalive request quota did not close connection"
        with socket.create_connection(ADDRESS, timeout=3) as idle:
            idle.settimeout(8)
            idle.sendall(b"GET /quota-idle HTTP/1.1\r\nHost: localhost\r\n\r\n")
            status, _, _ = read_response(idle)
            assert status == 200
            assert idle.recv(1) == b"", "Idle connection not closed"
        exchange("/quota-final")
    finally:
        first.close()
        second.close()
        if third is not None:
            third.close()
    print("Per-worker connection ceiling, released capacity, keepalive request cap and idle cleanup passed")


if sys.argv[1] == "supervisor":
    supervisor()
elif sys.argv[1] == "http-framing":
    framing()
elif sys.argv[1] == "http-backpressure":
    backpressure()
elif sys.argv[1] == "http-admission":
    admission()
elif sys.argv[1] == "http-connection-quotas":
    connection_quotas()
else:
    raise ValueError("Unknown runtime boundary")
