#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export LAB_POOL_ROOT="$(mktemp -d "$RUNNER_TEMP/foundation-pool.XXXXXXXX")"
contract="${SCENARIO#ecosystem-foundation-pool-}"
script="$GITHUB_WORKSPACE/qa/workloads/foundation-pool.php"
supervisor=
cleanup() {
  if [[ -n "$supervisor" ]]; then
    kill -TERM -- "-$supervisor" 2>/dev/null || :
    for attempt in {1..30}; do
      kill -0 -- "-$supervisor" 2>/dev/null || break
      sleep 0.1
    done
    kill -KILL -- "-$supervisor" 2>/dev/null || :
    wait "$supervisor" 2>/dev/null || :
  fi
  rm -rf "$LAB_POOL_ROOT"
}
trap cleanup EXIT
if [[ "$contract" == parent-guard ]]; then
  php "$script" guard "$contract" | tee "$EVIDENCE/pool-guard.log"
  exit
fi
php "$script" seed "$contract"
setsid php "$script" run "$contract" > "$EVIDENCE/pool-supervisor.log" 2>&1 &
supervisor=$!
php "$script" observe "$contract" | tee "$EVIDENCE/pool-observation.log"
for attempt in {1..150}; do
  kill -0 "$supervisor" 2>/dev/null || break
  sleep 0.1
done
if kill -0 "$supervisor" 2>/dev/null; then
  echo 'Pool supervisor did not stop after named control signal' >&2
  exit 1
fi
wait "$supervisor"
if kill -0 -- "-$supervisor" 2>/dev/null; then
  echo 'Pool leaked live child processes' >&2
  exit 1
fi
supervisor=
php "$script" verify "$contract" | tee "$EVIDENCE/pool-reconciliation.json"
