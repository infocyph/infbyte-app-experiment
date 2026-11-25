#!/usr/bin/env bash
set -Eeuo pipefail
cd "$PROJECT"
composer require grpc/grpc google/protobuf --no-interaction --prefer-dist --no-progress
composer check-platform-reqs --no-dev
composer show --format=json > "$EVIDENCE/packages.json"
umask 077
tls="$(mktemp -d "$RUNNER_TEMP/grpc-tls.XXXXXXXX")"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' -keyout "$tls/key.pem" -out "$tls/cert.pem" >/dev/null 2>&1
export GRPC_LAB_CA="$tls/cert.pem"
python3 -m venv "$RUNNER_TEMP/grpc-stream-venv"
python="$RUNNER_TEMP/grpc-stream-venv/bin/python"
"$python" -m pip install 'grpcio>=1.76,<2'
"$python" "$GITHUB_WORKSPACE/qa/workloads/grpc-stream-peer.py" "$tls" > "$EVIDENCE/peer.log" 2>&1 &
peer=$!
trap 'kill "$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; rm -rf "$tls"' EXIT
for i in $(seq 1 100); do
  if [[ -s "$tls/ready.json" ]]; then break; fi
  kill -0 "$peer"
  sleep 0.1
done
export GRPC_TEST_PORT="$(php -r 'echo json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR)["port"];' "$tls/ready.json")"
pids=()
for worker in $(seq 1 4); do
  php "$GITHUB_WORKSPACE/qa/workloads/grpc-stream-client.php" "$worker" > "$EVIDENCE/stream-$worker.log" 2>&1 &
  pids+=("$!")
done
failed=0
for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
cat "$EVIDENCE"/stream-*.log
(( failed == 0 ))
