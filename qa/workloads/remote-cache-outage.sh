#!/usr/bin/env bash
set -Eeuo pipefail
service="$1"
backend="$2"
export LAB_CACHE_FAULT_ROOT="$(mktemp -d "$RUNNER_TEMP/remote-cache-fault.XXXXXXXX")"
worker=""
cleanup() {
  if [[ -n "$worker" ]]; then kill "$worker" 2>/dev/null || :; wait "$worker" 2>/dev/null || :; fi
  docker logs "$service" > "$EVIDENCE/remote-cache-service.log" 2>&1 || :
  rm -rf "$LAB_CACHE_FAULT_ROOT"
}
trap cleanup EXIT
ready() {
  for attempt in {1..90}; do
    case "$backend" in
      mongodb) if docker exec "$service" mongosh --quiet --eval 'db.adminCommand({ping:1}).ok' | grep -qx 1; then return; fi ;;
      scylla) if docker exec "$service" cqlsh -e 'SELECT keyspace_name FROM system_schema.keyspaces;' >/dev/null 2>&1; then return; fi ;;
      *) return 1 ;;
    esac
    sleep 2
  done
  return 1
}
marker() {
  for attempt in {1..960}; do
    [[ -f "$LAB_CACHE_FAULT_ROOT/$1" ]] && return
    kill -0 "$worker"
    sleep 0.25
  done
  return 1
}
ready
timeout 720s php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" > "$EVIDENCE/remote-cache-outage.log" 2>&1 &
worker=$!
marker seeded
docker stop -t 30 "$service" >/dev/null
test "$(docker inspect -f '{{.State.Running}}' "$service")" = false
case "$backend" in mongodb) port=27017 ;; scylla) port=9042 ;; *) exit 1 ;; esac
if timeout 2 bash -c "exec 3<>/dev/tcp/127.0.0.1/$port" 2>/dev/null; then exit 1; fi
touch "$LAB_CACHE_FAULT_ROOT/stopped"
marker observed
docker start "$service" >/dev/null
ready
touch "$LAB_CACHE_FAULT_ROOT/restarted"
wait "$worker"
worker=""
cat "$EVIDENCE/remote-cache-outage.log"
