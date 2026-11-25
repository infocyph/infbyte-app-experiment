#!/usr/bin/env bash
set -Eeuo pipefail
export LAB_BOUNDARY_ROOT="$(mktemp -d "$RUNNER_TEMP/runtime-boundary.XXXXXXXX")"
mode="${SCENARIO#ecosystem-}"
setsid php "$GITHUB_WORKSPACE/qa/workloads/runtime-boundary-peer.php" "$mode" > "$EVIDENCE/runtime-peer.log" 2>&1 &
peer=$!
trap 'kill -TERM -- "-$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; rm -rf "$LAB_BOUNDARY_ROOT"' EXIT
python3 "$GITHUB_WORKSPACE/qa/workloads/runtime-boundary-client.py" "$mode" | tee "$EVIDENCE/runtime-client.log"
if [[ "$mode" == supervisor ]]; then
  for i in $(seq 1 100); do
    if ! kill -0 "$peer" 2>/dev/null; then break; fi
    sleep 0.1
  done
  ! kill -0 "$peer" 2>/dev/null
  wait "$peer"
  test ! -e "$LAB_BOUNDARY_ROOT/control.sock"
fi
