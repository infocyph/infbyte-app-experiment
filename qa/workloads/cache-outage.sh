#!/usr/bin/env bash
set -Eeuo pipefail
export LAB_CACHE_FAULT_ROOT="$(mktemp -d "$RUNNER_TEMP/cache-fault.XXXXXXXX")"
worker=""
cleanup() {
  if [[ -n "$worker" ]]; then kill "$worker" 2>/dev/null || :; wait "$worker" 2>/dev/null || :; fi
  docker rm -f lab-cache-fault >/dev/null 2>&1 || :
  rm -rf "$LAB_CACHE_FAULT_ROOT"
}
trap cleanup EXIT
docker run -d --name lab-cache-fault -p 127.0.0.1:17389:6379 redis:8 redis-server --appendonly yes --appendfsync always
ready() {
  for i in $(seq 1 30); do if docker exec lab-cache-fault redis-cli ping | grep -qx PONG; then return; fi; sleep 1; done
  return 1
}
marker() {
  for i in $(seq 1 120); do
    if [[ -f "$LAB_CACHE_FAULT_ROOT/$1" ]]; then return; fi
    kill -0 "$worker"
    sleep 0.25
  done
  return 1
}
ready
php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" cache-outage > "$EVIDENCE/cache-outage.log" 2>&1 &
worker=$!
marker seeded
docker stop lab-cache-fault >/dev/null
touch "$LAB_CACHE_FAULT_ROOT/stopped"
marker observed
docker start lab-cache-fault >/dev/null
ready
touch "$LAB_CACHE_FAULT_ROOT/restarted"
wait "$worker"
worker=""
cat "$EVIDENCE/cache-outage.log"
