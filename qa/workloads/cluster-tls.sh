#!/usr/bin/env bash
set -Eeuo pipefail
source "$GITHUB_WORKSPACE/qa/workloads/fixture-container-evidence.sh"
export LAB_CLUSTER_TLS_ROOT="$(mktemp -d "$RUNNER_TEMP/cluster-tls.XXXXXXXX")"
root="$LAB_CLUSTER_TLS_ROOT"
ports=(17379 17380 17381)
cleanup() {
  for port in "${ports[@]}"; do fixture_container_evidence "lab-cluster-tls-$port" || :; docker rm -f "lab-cluster-tls-$port" >/dev/null 2>&1 || :; done
  rm -rf "$root"
}
trap cleanup EXIT
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost \
  -addext subjectAltName=DNS:localhost,IP:127.0.0.1 -keyout "$root/key.pem" -out "$root/cert.pem" >/dev/null 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=untrusted \
  -keyout "$root/wrong.key" -out "$root/wrong.pem" >/dev/null 2>&1
cli() {
  local port="$1"
  shift
  docker exec "lab-cluster-tls-$port" redis-cli --tls --cacert /fixture/cert.pem -h 127.0.0.1 -p "$port" "$@"
}
ready() {
  local port attempt
  for port in "${ports[@]}"; do
    for attempt in {1..60}; do
      if cli "$port" ping | grep -qx PONG; then break; fi
      sleep 1
    done
    cli "$port" ping | grep -qx PONG
  done
}
cluster_ready() {
  local attempt port healthy
  for attempt in {1..60}; do
    healthy=1
    for port in "${ports[@]}"; do cli "$port" cluster info | grep -q 'cluster_state:ok' || healthy=0; done
    if [[ "$healthy" == 1 ]]; then return; fi
    sleep 1
  done
  return 1
}
for port in "${ports[@]}"; do
  docker run -d --name "lab-cluster-tls-$port" --network host --user root --entrypoint redis-server \
    -v "$root:/fixture:ro" redis:8 --port 0 --tls-port "$port" \
    --bind 127.0.0.1 --tls-cert-file /fixture/cert.pem --tls-key-file /fixture/key.pem \
    --tls-ca-cert-file /fixture/cert.pem --tls-auth-clients no --tls-cluster yes \
    --tls-replication yes --cluster-enabled yes --cluster-config-file nodes.conf \
    --cluster-announce-ip 127.0.0.1 --cluster-announce-tls-port "$port" \
    --cluster-announce-bus-port "$((port + 10000))" --appendonly yes --appendfsync always >/dev/null
done
ready
cli 17379 --cluster create 127.0.0.1:17379 127.0.0.1:17380 127.0.0.1:17381 --cluster-replicas 0 --cluster-yes
cluster_ready
cli 17379 cluster nodes > "$EVIDENCE/cluster-tls-topology.txt"
cd "$PROJECT"
probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
if [[ "$SCENARIO" == ecosystem-cache-redis-cluster-tls-restart ]]; then
  php "$probe" cache-redis-cluster-tls-seed | tee "$EVIDENCE/seed.log"
  for port in "${ports[@]}"; do docker stop -t 15 "lab-cluster-tls-$port" >/dev/null; done
  php "$probe" cache-redis-cluster-tls-offline | tee "$EVIDENCE/offline.log"
  for port in "${ports[@]}"; do docker start "lab-cluster-tls-$port" >/dev/null; done
  ready
  cluster_ready
  php "$probe" cache-redis-cluster-tls-recover | tee "$EVIDENCE/recovery.log"
else
  php "$probe" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/cluster-tls.log"
fi
