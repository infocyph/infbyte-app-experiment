#!/usr/bin/env bash
set -Eeuo pipefail
export LAB_COUNTER_FAULT_ROOT="$(mktemp -d "$RUNNER_TEMP/counter-uncertain.XXXXXXXX")"
port=6379
if [[ "$SCENARIO" == ecosystem-valkey-counter-uncertain ]]; then port=6380; fi
python3 "$GITHUB_WORKSPACE/qa/workloads/counter-uncertain-peer.py" "$LAB_COUNTER_FAULT_ROOT" "$port" > "$EVIDENCE/counter-peer.log" 2>&1 &
peer=$!
trap 'kill "$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; rm -rf "$LAB_COUNTER_FAULT_ROOT"' EXIT
for attempt in {1..100}; do
  [[ -f "$LAB_COUNTER_FAULT_ROOT/ready" ]] && break
  kill -0 "$peer"
  sleep 0.1
done
test -f "$LAB_COUNTER_FAULT_ROOT/ready"
timeout 45s php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log"
cp "$LAB_COUNTER_FAULT_ROOT"/fault-* "$EVIDENCE/"
