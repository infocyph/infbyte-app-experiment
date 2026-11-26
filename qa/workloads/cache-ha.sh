#!/usr/bin/env bash
set -euo pipefail
source "$GITHUB_WORKSPACE/qa/workloads/fixture-container-evidence.sh"
cd "$PROJECT"
script="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
if [[ "$SCENARIO" == ecosystem-scylla || "$SCENARIO" == ecosystem-cache-scylla-* ]]; then
  php -r 'if (!class_exists("Cassandra")) {fwrite(STDERR, "Native Cassandra PHP driver unavailable\n"); exit(1);}'
  docker run -d --name lab-scylla -p 127.0.0.1:9042:9042 scylladb/scylla:6.2 --smp 2 --memory 1G --overprovisioned 1 --developer-mode 1
  trap 'docker logs lab-scylla > "$EVIDENCE/scylla.log" 2>&1; docker rm -f lab-scylla >/dev/null' EXIT
  ready=0
  for attempt in $(seq 1 90); do
    if docker exec lab-scylla cqlsh -e "CREATE KEYSPACE IF NOT EXISTS cachelayer WITH replication = {'class':'SimpleStrategy','replication_factor':1};" >/dev/null 2>&1; then ready=1; break; fi
    sleep 2
  done
  test "$ready" = 1
  if [[ "$SCENARIO" == ecosystem-cache-scylla-outage-* ]]; then
    bash "$GITHUB_WORKSPACE/qa/workloads/remote-cache-outage.sh" lab-scylla scylla
  else
    php "$script" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log"
  fi
  exit
fi
ports=(17379 17380 17381 17382 17383 17384)
cleanup() {
  for port in "${ports[@]}"; do fixture_container_evidence "lab-ha-$port" || :; docker logs "lab-ha-$port" > "$EVIDENCE/redis-$port.log" 2>&1 || true; docker rm -f "lab-ha-$port" >/dev/null 2>&1 || true; done
}
trap cleanup EXIT
cli() { docker exec "lab-ha-$1" redis-cli -p "$1" "${@:2}"; }
addresses=()
for port in "${ports[@]}"; do
  docker run -d --name "lab-ha-$port" --network host redis:7 redis-server --port "$port" --bind 127.0.0.1 --cluster-enabled yes --cluster-config-file nodes.conf --cluster-node-timeout 2000 --cluster-announce-ip 127.0.0.1 --cluster-announce-port "$port" --cluster-announce-bus-port "$((port + 10000))" --appendonly yes
  addresses+=("127.0.0.1:$port")
done
for port in "${ports[@]}"; do
  for attempt in $(seq 1 30); do if cli "$port" ping | grep -q PONG; then break; fi; sleep 1; done
  cli "$port" ping | grep -q PONG
done
cli 17379 --cluster create "${addresses[@]}" --cluster-replicas 1 --cluster-yes
for attempt in $(seq 1 60); do
  ready=1
  for port in "${ports[@]}"; do
    cli "$port" cluster info | grep -q 'cluster_state:ok' || ready=0
    if [[ "$(cli "$port" --raw ROLE | head -1)" == slave ]]; then
      cli "$port" info replication | grep -q 'master_link_status:up' || ready=0
    else
      cli "$port" info replication | grep -q 'connected_slaves:1' || ready=0
    fi
  done
  if [[ "$ready" == 1 ]]; then break; fi
  sleep 1
done
test "$ready" = 1
php "$script" redis-failover-seed | tee "$EVIDENCE/seed.log"
primary=
replica=
for port in "${ports[@]}"; do
  if [[ "$(cli "$port" --raw ROLE | head -1)" != master ]]; then continue; fi
  info="$(cli "$port" info replication | tr -d '\r')"
  target="$(awk -F: '/^master_repl_offset:/{print $2}' <<< "$info")"
  follower="$(sed -n 's/^slave0:.*port=\([0-9]*\),state=online,.*/\1/p' <<< "$info")"
  test -n "$target" && test -n "$follower"
  for attempt in $(seq 1 30); do
    actual="$(cli "$follower" info replication | tr -d '\r' | awk -F: '/^slave_repl_offset:/{print $2}')"
    if [[ -n "$actual" ]] && (( actual >= target )); then break; fi
    sleep 1
  done
  test -n "$actual" && (( actual >= target ))
  if [[ -z "$primary" ]]; then primary="$port"; replica="$follower"; fi
done
test -n "$primary" && test -n "$replica"
cli "$primary" cluster nodes > "$EVIDENCE/cluster-before.txt"
docker stop -t 1 "lab-ha-$primary"
for attempt in $(seq 1 60); do
  if [[ "$(cli "$replica" --raw ROLE | head -1)" == master ]] && cli "$replica" cluster info | grep -q 'cluster_state:ok'; then break; fi
  sleep 1
done
test "$(cli "$replica" --raw ROLE | head -1)" = master
cli "$replica" cluster info | grep -q 'cluster_state:ok'
cli "$replica" cluster nodes > "$EVIDENCE/cluster-after.txt"
php "$script" redis-failover-verify | tee "$EVIDENCE/ecosystem.log"
