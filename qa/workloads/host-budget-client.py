"""Independent HTTP client for actual hosted Runwire request/response limits."""
import hashlib
import http.client
import json
import os
from pathlib import Path
import sys
import time
from concurrent.futures import ThreadPoolExecutor

ROOT = Path(os.environ["PROJECT"]) / "storage/lab-host-budget"
MODE = sys.argv[1]
BODY = hashlib.sha256(b"host-budget").digest() * 128


def exchange(path, method="GET", body=None):
    connection = http.client.HTTPConnection("127.0.0.1", 18781, timeout=8)
    try:
        connection.request(method, path, body=body, headers={"Connection": "close", "Content-Type": "application/octet-stream"})
        response = connection.getresponse()
        return response.status, dict((k.lower(), v) for k, v in response.getheaders()), response.read()
    finally:
        connection.close()


def ready():
    for _ in range(100):
        try:
            status, _, body = exchange("/health")
            if status == 200 and body == b"healthy":
                return
        except (OSError, http.client.HTTPException):
            pass
        time.sleep(0.2)
    raise AssertionError("Host never became ready")


def records(name):
    path = ROOT / (name + ".jsonl")
    if not path.exists():
        return []
    return [json.loads(line) for line in path.read_text().splitlines() if line]


def one(i):
    suffix = "-" + str(i)
    if MODE == "request":
        path = "/upload" + suffix
        status, _, result = exchange(path, "POST", BODY)
        assert status == 200 and result == hashlib.sha256(BODY).hexdigest().encode()
    elif MODE == "head":
        path = "/head" + suffix
        status, headers, result = exchange(path, "HEAD")
        assert status == 200 and not result and headers.get("content-length") == "4097", (status, headers)
    else:
        path = "/" + MODE + suffix
        status, _, result = exchange(path)
        assert status == 200 and result == BODY, (status, len(result))
    return path


ready()
if MODE == "request":
    # Avoid concurrent invalid requests killing all host workers at the same
    # instant. Each rejected request must fail before entering the handler.
    for i in range(4):
        rejected = "/upload-denied" + str(i)
        try:
            status, _, payload = exchange(rejected, "POST", BODY + b"x")
            assert not 200 <= status < 300, (status, payload)
        except (OSError, http.client.HTTPException):
            pass
        ready()
        assert rejected not in [row["target"] for row in records("entered")]

with ThreadPoolExecutor(max_workers=4) as pool:
    expected = list(pool.map(one, range(16)))
ready()
for _ in range(100):
    terminal = [row["target"] for row in records("terminal")]
    if all(terminal.count(path) == 1 for path in expected):
        break
    time.sleep(0.05)
entered = [row["target"] for row in records("entered")]
assert all(entered.count(path) == 1 and terminal.count(path) == 1 for path in expected)
assert not any(path.startswith("/upload-denied") for path in entered)
print(json.dumps({"host": os.environ["HOST_MODE"], "mode": MODE, "verified": len(expected),
                  "worker_pids": sorted({row["pid"] for row in records("terminal") if row["target"] in expected})}))
