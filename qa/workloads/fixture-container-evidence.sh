#!/usr/bin/env bash
# Retain safe state and bounded credential-filtered diagnostics before disposal.
fixture_container_evidence() {
  local name="$1"
  python3 - "$name" "$EVIDENCE" <<'PY'
import json
import pathlib
import re
import subprocess
import sys

name, directory = sys.argv[1:]
if not re.fullmatch(r"[A-Za-z0-9_.-]+", name):
    raise SystemExit("Invalid fixture container name.")
root = pathlib.Path(directory)
inspection = subprocess.run(["docker", "inspect", name], capture_output=True, text=True)
if inspection.returncode:
    raise SystemExit(0)
container = json.loads(inspection.stdout)[0]
state = container["State"]
safe = {key: state.get(key) for key in ("Status", "Running", "OOMKilled", "ExitCode", "StartedAt", "FinishedAt")}
safe["image"] = container["Config"]["Image"]
(root / f"container-{name}-state.json").write_text(json.dumps(safe, indent=2) + "\n")
print(f"Fixture {name}: {json.dumps(safe)}")
if safe["Running"] or not safe["ExitCode"]:
    raise SystemExit(0)
logs = subprocess.run(["docker", "logs", "--tail", "100", name], capture_output=True, text=True, errors="replace")
message = logs.stdout + logs.stderr
for entry in container["Config"].get("Env", []):
    key, _, value = entry.partition("=")
    if value and re.search(r"PASSWORD|PASS$|SECRET|COOKIE|TOKEN|KEY|AUTH", key, re.I):
        message = message.replace(value, "[redacted]")
lines = []
for line in message.splitlines():
    if re.search(r"password|secret|cookie|token|credential|authorization|private.key", line, re.I):
        continue
    if re.search(r"error|fatal|failed|denied|panic|address already|cannot|unable|not found|permission|killed|abort", line, re.I):
        lines.append(line[:800])
(root / f"container-{name}-errors.log").write_text("\n".join(lines[-40:]) + "\n")
for line in lines[-10:]:
    print(line)
PY
}
