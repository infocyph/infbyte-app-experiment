#!/usr/bin/env bash
set -Eeuo pipefail
export LAB_COMMIT_PROXY_ROOT="$(mktemp -d "$RUNNER_TEMP/commit-proxy.XXXXXXXX")"
export PGGSSENCMODE=disable
python3 "$GITHUB_WORKSPACE/qa/workloads/database-commit-proxy.py" "$LAB_COMMIT_PROXY_ROOT" > "$EVIDENCE/commit-proxy.log" 2>&1 &
peer=$!
trap 'kill "$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; rm -rf "$LAB_COMMIT_PROXY_ROOT"' EXIT
for i in $(seq 1 100); do
  if [[ -s "$LAB_COMMIT_PROXY_ROOT/port" ]]; then break; fi
  kill -0 "$peer"
  sleep 0.1
done
export LAB_COMMIT_PROXY_PORT="$(cat "$LAB_COMMIT_PROXY_ROOT/port")"
timeout 60s php "$GITHUB_WORKSPACE/qa/workloads/database.php" commit-uncertain | tee "$EVIDENCE/database.log"
test -f "$LAB_COMMIT_PROXY_ROOT/ack-dropped"
printf 'backend COMMIT acknowledgement observed and withheld\n' > "$EVIDENCE/fault-marker.txt"
