#!/usr/bin/env bash
set -Eeuo pipefail
root="$(mktemp -d "$RUNNER_TEMP/counter-election.XXXXXXXX")"
image=redis:8
server=redis-server
cli=redis-cli
export LAB_COUNTER_BACKEND=redis
if [[ "$SCENARIO" == ecosystem-valkey-counter-election ]]; then
  image=valkey/valkey:8
  server=valkey-server
  cli=valkey-cli
  export LAB_COUNTER_BACKEND=valkey
fi
cleanup() {
  for name in lab-counter-primary lab-counter-replica lab-counter-sentinel; do
    docker logs "$name" > "$EVIDENCE/$name.log" 2>&1 || :
    docker rm -f "$name" >/dev/null 2>&1 || :
  done
  rm -rf "$root"
}
trap cleanup EXIT
docker run -d --name lab-counter-primary --network host "$image" "$server" --bind 127.0.0.1 --port 17389 --appendonly yes --appendfsync always --repl-diskless-sync-delay 0
docker run -d --name lab-counter-replica --network host "$image" "$server" --bind 127.0.0.1 --port 17390 --appendonly yes --appendfsync always --replicaof 127.0.0.1 17389
cat > "$root/sentinel.conf" <<CONF
bind 127.0.0.1
port 17391
dir /tmp
sentinel monitor lab-counter 127.0.0.1 17389 1
sentinel down-after-milliseconds lab-counter 1000
sentinel failover-timeout lab-counter 10000
sentinel parallel-syncs lab-counter 1
CONF
docker run -d --name lab-counter-sentinel --network host --user "$(id -u):$(id -g)" -v "$root:/fixture" "$image" "$server" /fixture/sentinel.conf --sentinel
ready=0
for attempt in {1..60}; do
  if docker exec lab-counter-replica "$cli" -p 17390 info replication | grep -q 'master_link_status:up' \
    && docker exec lab-counter-sentinel "$cli" -p 17391 sentinel replicas lab-counter | grep -qx 17390; then
    ready=1; break
  fi
  sleep 1
done
test "$ready" = 1
probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
php "$probe" counter-election-seed | tee "$EVIDENCE/counter-election-seed.log"
docker stop -t 1 lab-counter-primary >/dev/null
ready=0
for attempt in {1..60}; do
  if docker exec lab-counter-sentinel "$cli" -p 17391 --raw sentinel get-master-addr-by-name lab-counter | grep -qx 17390 \
    && docker exec lab-counter-replica "$cli" -p 17390 --raw ROLE | head -1 | grep -qx master; then
    ready=1; break
  fi
  sleep 1
done
test "$ready" = 1
docker exec lab-counter-sentinel "$cli" -p 17391 sentinel master lab-counter > "$EVIDENCE/elected-primary.txt"
php "$probe" counter-election-verify | tee "$EVIDENCE/counter-election-verify.log"
