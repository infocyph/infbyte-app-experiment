#!/usr/bin/env bash
set -Eeuo pipefail
source "$GITHUB_WORKSPACE/qa/workloads/fixture-container-evidence.sh"
root="$(mktemp -d "$RUNNER_TEMP/otp-cluster.XXXXXXXX")"
image=redis:8
server=redis-server
cli=redis-cli
if [[ "$SCENARIO" == ecosystem-otp-cluster-valkey-* ]]; then
  image=valkey/valkey:8; server=valkey-server; cli=valkey-cli
fi
factor="${SCENARIO#ecosystem-otp-cluster-}"
factor="${factor#*-}"
fault=promotion
case "$factor" in
  minority-*) fault=minority; factor="${factor#minority-}" ;;
  shard-loss-*) fault=shard-loss; factor="${factor#shard-loss-}" ;;
esac
ports=(17379 17380 17381 17382 17383 17384)
cleanup() {
  for port in "${ports[@]}"; do fixture_container_evidence "lab-otp-$port" || :; docker rm -f "lab-otp-$port" >/dev/null 2>&1 || :; done
  rm -rf "$root"
}
trap cleanup EXIT
run() { local port="$1"; shift; docker exec "lab-otp-$port" "$cli" -p "$port" --raw "$@"; }
for port in "${ports[@]}"; do
  docker run -d --name "lab-otp-$port" --network host "$image" "$server" --bind 127.0.0.1 --port "$port" \
    --cluster-enabled yes --cluster-config-file nodes.conf --cluster-node-timeout 2000 --cluster-require-full-coverage yes --cluster-allow-reads-when-down no \
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
if [[ "$fault" != promotion ]]; then
  stopped=()
  survivors=()
  for port in "${ports[@]}"; do
    if [[ "$fault" == minority ]]; then
      if [[ "$port" == "$primary" || "$port" == "$replica" ]]; then survivors+=("$port"); else stopped+=("$port"); fi
    else
      if [[ "$port" == "$primary" || "$port" == "$replica" ]]; then stopped+=("$port"); else survivors+=("$port"); fi
    fi
  done
  names=()
  for port in "${stopped[@]}"; do names+=("lab-otp-$port"); done
  docker kill "${names[@]}" >/dev/null
  for port in "${stopped[@]}"; do
    test "$(docker inspect -f '{{.State.Running}}' "lab-otp-$port")" = false
    if timeout 2 bash -c "exec 3<>/dev/tcp/127.0.0.1/$port" 2>/dev/null; then exit 1; fi
  done
  survivor="${survivors[0]}"
  ready=0
  for attempt in {1..60}; do
    if run "$survivor" cluster info | grep -q 'cluster_state:fail'; then ready=1; break; fi
    sleep 1
  done
  test "$ready" = 1
  for port in "${survivors[@]}"; do
    run "$port" ping | grep -qx PONG
    run "$port" cluster info | grep -q 'cluster_state:fail'
  done
  run "$survivor" cluster info > "$EVIDENCE/cluster-unavailable.txt"
  native="$(run "$survivor" set lab-otp-outage-native-control rejected 2>&1 || :)"
  grep -q CLUSTERDOWN <<< "$native"
  printf '%s\n' "$native" > "$EVIDENCE/cluster-native-rejection.txt"
  addresses=()
  for port in "${survivors[@]}"; do addresses+=("127.0.0.1:$port"); done
  export LAB_CLUSTER_SEEDS="$(IFS=,; echo "${addresses[*]}")"
  timeout 90s php "$probe" otp-cluster-offline "$factor" | tee "$EVIDENCE/otp-cluster-offline.log"
  docker start "${names[@]}" >/dev/null
  ready=0
  for attempt in {1..120}; do
    all=1
    for port in "${ports[@]}"; do
      if ! run "$port" cluster info 2>/dev/null | grep -q 'cluster_state:ok'; then all=0; break; fi
    done
    if [[ "$all" == 1 ]]; then ready=1; break; fi
    sleep 1
  done
  test "$ready" = 1
  run "$survivor" cluster nodes > "$EVIDENCE/cluster-restored.txt"
  run "$survivor" -c exists lab-otp-outage-native-control | grep -qx 0
  addresses=()
  for port in "${ports[@]}"; do addresses+=("127.0.0.1:$port"); done
  export LAB_CLUSTER_SEEDS="$(IFS=,; echo "${addresses[*]}")"
  timeout 120s php "$probe" otp-cluster-promotion-verify "$factor" | tee "$EVIDENCE/otp-cluster-recovery.log"
  exit 0
fi
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
