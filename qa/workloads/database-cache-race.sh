#!/usr/bin/env bash
set -euo pipefail
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
php "$probe" cache-race-seed
pids=()
cleanup() {
  for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || :; done
  for pid in "${pids[@]}"; do wait "$pid" 2>/dev/null || :; done
}
trap cleanup EXIT
for generation in 1 2; do
  pids=()
  for worker in {0..11}; do
    timeout 90s php "$probe" cache-race-read "$generation" "$worker" > "$EVIDENCE/race-$generation-$worker.json" 2> "$EVIDENCE/race-$generation-$worker.log" &
    pids+=("$!")
  done
  ready=0
  for attempt in {1..450}; do
    count=0
    for worker in {0..11}; do
      if [[ -f "$EVIDENCE/ready-$generation-$worker" ]]; then (( count+=1 )); fi
    done
    if (( count == 12 )); then ready=1; break; fi
    sleep 0.1
  done
  test "$ready" = 1
  touch "$EVIDENCE/go-$generation"
  failed=0
  for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
  pids=()
  if (( failed )); then cat "$EVIDENCE"/race-"$generation"-*.log >&2; exit 1; fi
  php "$probe" cache-race-verify "$generation"
  if (( generation == 1 )); then php "$probe" cache-race-advance; fi
done
