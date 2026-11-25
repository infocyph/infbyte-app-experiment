#!/usr/bin/env bash
set -Eeuo pipefail
root="$(mktemp -d "$RUNNER_TEMP/otp-cluster.XXXXXXXX")"
image=redis:8
server=redis-server
cli=redis-cli
if [[ "$SCENARIO" == ecosystem-otp-cluster-valkey-* ]]; then
  image=valkey/valkey:8; server=valkey-server; cli=valkey-cli
fi
factor="${SCENARIO#ecosystem-otp-cluster-}"
factor="${factor#*-}"
ports=(17379 17380 17381 17382 17383 17384)
cleanup() {
  for port in "${ports[@]}"; do docker rm -f "lab-otp-$port" >/dev/null 2>&1 || :; done
  rm -rf "$root"
}
trap cleanup EXIT
run() { local port="$1"; shift; docker exec "lab-otp-$port" "$cli" -p "$port" --raw "$@"; }
for port in "${ports[@]}"; do
  docker run -d --name "lab-otp-$port" --network host "$image" "$server" --bind 127.0.0.1 --port "$port" \
    --cluster-enabled yes --cluster-config-file nodes.conf --cluster-node-timeout 2000 \
    --cluster-announce-ip 127.0.0.1 --cluster-announce-port "$port" --cluster-announce-bus-port "$((port + 10000))" \
    --appendonly yes --appendfsync always --repl-diskless-sync-delay 0 >/dev/null
done
addresses=()
for port in "${ports[@]}"; do
  for attempt in {1..60}; do if run "$port" ping | grep -qx PONG; then break; fi; sleep 1; done
  run "$port" ping | grep -qx PONG
  addresses+=("127.0.0.1:$port")
done
run 17379 --cluster create "${addresses[@]}" --cluster-replicas 1 --cluster-yes > "$EVIDENCE/cluster-create.txt"
ready=0
for attempt in {1..60}; do
  if run 17379 cluster info | grep -q 'cluster_state:ok' && [[ "$(run 17379 cluster nodes | awk '$3 ~ /slave/ && $8=="connected"{n++}END{print n+0}')" == 3 ]]; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
umask 077
export LAB_OTP_ELECTION_STATE="$root/state.json"
export LAB_CLUSTER_SEEDS="$(IFS=,; echo "${addresses[*]}")"
probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
php "$probe" otp-cluster-promotion-seed "$factor" | tee "$EVIDENCE/otp-cluster-seed.log"
run 17379 cluster nodes > "$root/nodes"
primary=""
while read -r id endpoint flags owner rest; do
  [[ "$flags" == *master* ]] || continue
  port="${endpoint#*:}"; port="${port%%@*}"
  if [[ "$(run "$port" dbsize)" -gt 0 ]]; then
    primary="$port"; primary_id="$id"; break
  fi
done < "$root/nodes"
test -n "$primary"
replica_endpoint="$(awk -v id="$primary_id" '$4==id && $3~/slave/{print $2;exit}' "$root/nodes")"
test -n "$replica_endpoint"
replica="${replica_endpoint#*:}"; replica="${replica%%@*}"
offset="$(run "$primary" info replication | tr -d '\r' | sed -n 's/^master_repl_offset://p')"
test "$offset" -ge 0
caught_up=0
for attempt in {1..200}; do
  replica_offset="$(run "$replica" info replication | tr -d '\r' | sed -n 's/^slave_repl_offset://p')"
  if [[ "$replica_offset" =~ ^[0-9]+$ ]] && (( replica_offset >= offset )) && run "$replica" info replication | grep -q 'master_link_status:up'; then caught_up=1; break; fi
  sleep 0.1
done
test "$caught_up" = 1
printf 'primary=%s replica=%s offset=%s replica_offset=%s\n' "$primary" "$replica" "$offset" "$replica_offset" > "$EVIDENCE/replication-barrier.txt"
docker stop -t 1 "lab-otp-$primary" >/dev/null
if timeout 2 bash -c "exec 3<>/dev/tcp/127.0.0.1/$primary" 2>/dev/null; then exit 1; fi
ready=0
for attempt in {1..90}; do
  if run "$replica" role | head -1 | grep -qx master && run "$replica" cluster info | grep -q 'cluster_state:ok'; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
run "$replica" cluster nodes > "$EVIDENCE/cluster-promoted.txt"
addresses=("127.0.0.1:$replica")
for port in "${ports[@]}"; do
  [[ "$port" == "$primary" || "$port" == "$replica" ]] || addresses+=("127.0.0.1:$port")
done
export LAB_CLUSTER_SEEDS="$(IFS=,; echo "${addresses[*]}")"
php "$probe" otp-cluster-promotion-verify "$factor" | tee "$EVIDENCE/otp-cluster-recovery.log"
