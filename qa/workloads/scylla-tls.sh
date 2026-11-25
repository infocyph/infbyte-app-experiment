#!/usr/bin/env bash
set -Eeuo pipefail
export LAB_SCYLLA_TLS_ROOT="$(mktemp -d "$RUNNER_TEMP/scylla-tls.XXXXXXXX")"
root="$LAB_SCYLLA_TLS_ROOT"
cleanup() {
  docker logs lab-scylla-tls > "$EVIDENCE/scylla-tls.log" 2>&1 || :
  docker rm -f lab-scylla-tls >/dev/null 2>&1 || :
  rm -rf "$root"
}
trap cleanup EXIT
php -r 'if (!class_exists("Cassandra")) {fwrite(STDERR, "Native Cassandra PHP driver unavailable\n"); exit(1);}'
subject='127.0.0.1'
san='IP:127.0.0.1,DNS:localhost'
if [[ "$SCENARIO" == ecosystem-cache-scylla-tls-identity ]]; then
  subject='wrong.example.test'
  san='DNS:wrong.example.test'
fi
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$subject" \
  -addext "subjectAltName=$san" -keyout "$root/key.pem" -out "$root/cert.pem" >/dev/null 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=untrusted \
  -keyout "$root/wrong.key" -out "$root/wrong.pem" >/dev/null 2>&1
docker run -d --name lab-scylla-tls --network host --user root \
  -v "$root:/fixture:ro" --entrypoint /bin/bash scylladb/scylla:6.2 -ec '
    install -d -o scylla -g scylla -m 700 /lab-tls
    install -o scylla -g scylla -m 600 /fixture/key.pem /lab-tls/key.pem
    install -o scylla -g scylla -m 644 /fixture/cert.pem /lab-tls/cert.pem
    if ! grep -q "^client_encryption_options:" /etc/scylla/scylla.yaml; then
      cat >> /etc/scylla/scylla.yaml <<EOF

client_encryption_options:
    enabled: true
    certificate: /lab-tls/cert.pem
    keyfile: /lab-tls/key.pem
    require_client_auth: false
EOF
    else
      grep -q "certificate: /lab-tls/cert.pem" /etc/scylla/scylla.yaml
    fi
    exec /docker-entrypoint.py "$@"
  ' -- --smp 2 --memory 1G --overprovisioned 1 --developer-mode 1 \
  --listen-address 127.0.0.1 --rpc-address 127.0.0.1 --seeds 127.0.0.1 >/dev/null
cd "$PROJECT"
probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
ready() {
  local attempt
  for attempt in {1..90}; do
    if timeout 4 openssl s_client -connect 127.0.0.1:9042 -CAfile "$root/cert.pem" \
      -verify_return_error </dev/null >/dev/null 2>&1; then
      if [[ "$SCENARIO" == ecosystem-cache-scylla-tls-identity ]]; then return; fi
      if php "$probe" cache-scylla-tls-prepare > "$EVIDENCE/scylla-ready.log" 2>&1; then return; fi
    fi
    sleep 2
  done
  return 1
}
ready
if [[ "$SCENARIO" == ecosystem-cache-scylla-tls-restart ]]; then
  php "$probe" cache-scylla-tls-seed | tee "$EVIDENCE/seed.log"
  docker stop -t 20 lab-scylla-tls >/dev/null
  php "$probe" cache-scylla-tls-offline | tee "$EVIDENCE/offline.log"
  docker start lab-scylla-tls >/dev/null
  ready
  php "$probe" cache-scylla-tls-recover | tee "$EVIDENCE/recovery.log"
else
  php "$probe" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/scylla-tls-contract.log"
fi
