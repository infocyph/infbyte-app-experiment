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
case "$SCENARIO" in
  ecosystem-cache-valkey-outage|ecosystem-foundation-risk-outage-valkey|ecosystem-foundation-cache-outage-*-valkey)
    docker run -d --name lab-cache-fault -p 127.0.0.1:17389:6379 valkey/valkey:8 valkey-server --appendonly yes --appendfsync always
    ;;
  ecosystem-cache-memcached-outage|ecosystem-foundation-risk-outage-memcached|ecosystem-foundation-cache-outage-*-memcached)
    docker run -d --name lab-cache-fault -p 127.0.0.1:17389:11211 memcached:1.6
    ;;
  *)
    docker run -d --name lab-cache-fault -p 127.0.0.1:17389:6379 redis:8 redis-server --appendonly yes --appendfsync always
    ;;
esac
ready() {
  for i in $(seq 1 30); do
    case "$SCENARIO" in
      ecosystem-cache-valkey-outage|ecosystem-foundation-risk-outage-valkey|ecosystem-foundation-cache-outage-*-valkey) if docker exec lab-cache-fault valkey-cli ping | grep -qx PONG; then return; fi ;;
      ecosystem-cache-memcached-outage|ecosystem-foundation-risk-outage-memcached|ecosystem-foundation-cache-outage-*-memcached) if php -r '$c=new Memcached();$c->addServer("127.0.0.1",17389);$v=$c->getVersion();exit(is_array($v)&&($v["127.0.0.1:17389"]??"0.0.0")!=="0.0.0"?0:1);'; then return; fi ;;
      *) if docker exec lab-cache-fault redis-cli ping | grep -qx PONG; then return; fi ;;
    esac
    sleep 1
  done
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
php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" > "$EVIDENCE/cache-outage.log" 2>&1 &
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
