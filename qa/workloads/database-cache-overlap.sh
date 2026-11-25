#!/usr/bin/env bash
set -Eeuo pipefail
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
php "$probe" cache-race-seed
pids=()
cleanup() {
  for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || :; done
  for pid in "${pids[@]}"; do wait "$pid" 2>/dev/null || :; done
}
trap cleanup EXIT
timeout 60s php "$probe" cache-overlap-hold > "$EVIDENCE/overlap-gate.log" 2>&1 &
pids+=("$!")
for attempt in {1..200}; do
  [[ -f "$EVIDENCE/overlap-held" ]] && break
  kill -0 "$!"
  sleep 0.1
done
test -f "$EVIDENCE/overlap-held"
timeout 60s php "$probe" cache-overlap-read > "$EVIDENCE/overlap-read.log" 2>&1 &
pids+=("$!")
timeout 40s php "$probe" cache-overlap-commit | tee "$EVIDENCE/overlap-commit.log"
failed=0
for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
pids=()
cat "$EVIDENCE/overlap-read.log"
((failed == 0))
php "$probe" cache-overlap-verify | tee "$EVIDENCE/overlap-verify.log"
