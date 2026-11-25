#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export LAB_WIRE_ROOT="$(mktemp -d "$RUNNER_TEMP/wire.XXXXXXXX")"
mode="${SCENARIO#ecosystem-wire-}"
if [[ "$mode" == http2 ]]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost' -keyout "$LAB_WIRE_ROOT/key.pem" -out "$LAB_WIRE_ROOT/cert.pem" >/dev/null 2>&1
fi
python=python3
if [[ "$mode" == http2 || "$mode" == websocket ]]; then
  python3 -m venv "$RUNNER_TEMP/wire-venv"
  python="$RUNNER_TEMP/wire-venv/bin/python"
  "$python" -m pip install 'h2>=4,<5' 'websockets>=15,<17'
fi
setsid php "$GITHUB_WORKSPACE/qa/workloads/wire-peer.php" "$mode" > "$EVIDENCE/wire-peer.log" 2>&1 &
peer=$!
trap 'kill -TERM -- "-$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; rm -rf "$LAB_WIRE_ROOT"' EXIT
"$python" "$GITHUB_WORKSPACE/qa/workloads/wire-client.py" "$mode" | tee "$EVIDENCE/wire-client.log"
