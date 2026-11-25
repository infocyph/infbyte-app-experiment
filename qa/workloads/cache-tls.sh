#!/usr/bin/env bash
set -euo pipefail
root="$(mktemp -d "$RUNNER_TEMP/cache-tls.XXXXXXXX")"
name="lab-cache-tls"
trap 'docker rm -f "$name" >/dev/null 2>&1 || :; rm -rf "$root"' EXIT
chmod 700 "$root"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost -addext subjectAltName=DNS:localhost -keyout "$root/key.pem" -out "$root/cert.pem" >/dev/null 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=untrusted -keyout "$root/wrong.key" -out "$root/wrong.pem" >/dev/null 2>&1
docker run -d --name "$name" --user "$(id -u):$(id -g)" -p 127.0.0.1:17390:6379 -v "$root:/tls:ro" redis:8 redis-server --port 0 --tls-port 6379 --tls-cert-file /tls/cert.pem --tls-key-file /tls/key.pem --tls-ca-cert-file /tls/cert.pem --tls-auth-clients no --save "" --appendonly no >/dev/null
ready=0
for attempt in {1..40}; do
  if docker exec "$name" redis-cli --tls --cacert /tls/cert.pem -h localhost PING | grep -qx PONG; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
export LAB_CACHE_TLS_ROOT="$root"
php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" cache-tls | tee "$EVIDENCE/ecosystem.log"
