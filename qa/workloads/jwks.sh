#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
cd "$PROJECT"
composer require 'nyholm/psr7:^1.8' --no-interaction --prefer-dist --no-progress
composer check-platform-reqs --no-dev
composer show --format=json > "$EVIDENCE/packages.json"
export LAB_JWKS_ROOT="$(mktemp -d "$RUNNER_TEMP/jwks.XXXXXXXX")"
peer=""
cleanup() {
  if [[ -n "$peer" ]]; then kill "$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; fi
  rm -rf "$LAB_JWKS_ROOT"
}
trap cleanup EXIT
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=issuer.example.test' -addext 'subjectAltName=DNS:issuer.example.test' -keyout "$LAB_JWKS_ROOT/key.pem" -out "$LAB_JWKS_ROOT/cert.pem" >/dev/null 2>&1
python3 "$GITHUB_WORKSPACE/qa/workloads/jwks-peer.py" "$LAB_JWKS_ROOT" > "$EVIDENCE/jwks-peer.log" 2>&1 &
peer=$!
for i in $(seq 1 100); do
  if [[ -s "$LAB_JWKS_ROOT/port" ]]; then break; fi
  kill -0 "$peer"
  sleep 0.1
done
export LAB_JWKS_PORT="$(cat "$LAB_JWKS_ROOT/port")"
php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" remote-jwks | tee "$EVIDENCE/ecosystem.log"
