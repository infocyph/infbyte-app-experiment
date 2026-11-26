#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export LAB_WIRE_ROOT="$(mktemp -d "$RUNNER_TEMP/wire.XXXXXXXX")"
mode="${SCENARIO#ecosystem-wire-}"
if [[ "$mode" == http3-aggregate-* || "$mode" == http2-aggregate-* || "$mode" == http2 || "$mode" == http2-flow || "$mode" == http2-admission || "$mode" == http3 || "$mode" == http3-flow || "$mode" == http3-admission ]]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost' -keyout "$LAB_WIRE_ROOT/key.pem" -out "$LAB_WIRE_ROOT/cert.pem" >/dev/null 2>&1
fi
if [[ "$mode" == mtls ]]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=Lab CA' -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign' -addext 'subjectKeyIdentifier=hash' -keyout "$LAB_WIRE_ROOT/ca.key" -out "$LAB_WIRE_ROOT/ca.pem" >/dev/null 2>&1
  for identity in server client; do
    openssl req -newkey rsa:2048 -nodes -subj "/CN=$identity" -keyout "$LAB_WIRE_ROOT/$identity.key" -out "$LAB_WIRE_ROOT/$identity.csr" >/dev/null 2>&1
  done
  printf '%s\n' 'subjectAltName=DNS:localhost' 'basicConstraints=critical,CA:FALSE' 'keyUsage=critical,digitalSignature,keyEncipherment' 'extendedKeyUsage=serverAuth' 'subjectKeyIdentifier=hash' 'authorityKeyIdentifier=keyid,issuer' > "$LAB_WIRE_ROOT/server.ext"
  printf '%s\n' 'basicConstraints=critical,CA:FALSE' 'keyUsage=critical,digitalSignature' 'extendedKeyUsage=clientAuth' 'subjectKeyIdentifier=hash' 'authorityKeyIdentifier=keyid,issuer' > "$LAB_WIRE_ROOT/client.ext"
  openssl x509 -req -in "$LAB_WIRE_ROOT/server.csr" -CA "$LAB_WIRE_ROOT/ca.pem" -CAkey "$LAB_WIRE_ROOT/ca.key" -CAcreateserial -days 1 -extfile "$LAB_WIRE_ROOT/server.ext" -out "$LAB_WIRE_ROOT/cert.pem" >/dev/null 2>&1
  mv "$LAB_WIRE_ROOT/server.key" "$LAB_WIRE_ROOT/key.pem"
  openssl x509 -req -in "$LAB_WIRE_ROOT/client.csr" -CA "$LAB_WIRE_ROOT/ca.pem" -CAkey "$LAB_WIRE_ROOT/ca.key" -CAserial "$LAB_WIRE_ROOT/ca.srl" -days 1 -extfile "$LAB_WIRE_ROOT/client.ext" -out "$LAB_WIRE_ROOT/client.pem" >/dev/null 2>&1
  openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=Foreign Client' -keyout "$LAB_WIRE_ROOT/foreign.key" -out "$LAB_WIRE_ROOT/foreign.pem" >/dev/null 2>&1
fi
if [[ "$mode" == http3-aggregate-* || "$mode" == http3 || "$mode" == http3-flow || "$mode" == http3-admission ]]; then
  php -r 'if (!extension_loaded("quic")) {fwrite(STDERR,"Native ext-quic is required.\n");exit(1);}'
fi
python=python3
if [[ "$mode" == http3-aggregate-* || "$mode" == http2-aggregate-* || "$mode" == http2 || "$mode" == http2-flow || "$mode" == http2-admission || "$mode" == websocket || "$mode" == http3 || "$mode" == http3-flow || "$mode" == http3-admission ]]; then
  python3 -m venv "$RUNNER_TEMP/wire-venv"
  python="$RUNNER_TEMP/wire-venv/bin/python"
  "$python" -m pip install 'h2>=4,<5' 'websockets>=15,<17' 'aioquic>=1.2,<2'
fi
setsid php "$GITHUB_WORKSPACE/qa/workloads/wire-peer.php" "$mode" > "$EVIDENCE/wire-peer.log" 2>&1 &
peer=$!
trap 'kill -TERM -- "-$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; rm -rf "$LAB_WIRE_ROOT"' EXIT
if [[ "$mode" == http2-admission || "$mode" == http3-admission ]]; then
  "$python" "$GITHUB_WORKSPACE/qa/workloads/wire-admission.py" "$mode" | tee "$EVIDENCE/wire-client.log"
elif [[ "$mode" == http2-aggregate-* ]]; then
  "$python" "$GITHUB_WORKSPACE/qa/workloads/wire-http2-aggregate.py" "$mode" | tee "$EVIDENCE/wire-client.log"
elif [[ "$mode" == http2-flow ]]; then
  "$python" "$GITHUB_WORKSPACE/qa/workloads/wire-http2-flow.py" | tee "$EVIDENCE/wire-client.log"
elif [[ "$mode" == http3-aggregate-* ]]; then
  "$python" "$GITHUB_WORKSPACE/qa/workloads/wire-http3-aggregate.py" "$mode" | tee "$EVIDENCE/wire-client.log"
elif [[ "$mode" == http3-flow ]]; then
  "$python" "$GITHUB_WORKSPACE/qa/workloads/wire-http3-flow.py" | tee "$EVIDENCE/wire-client.log"
elif [[ "$mode" == http3 || "$mode" == mtls ]]; then
  "$python" "$GITHUB_WORKSPACE/qa/workloads/wire-secure-client.py" "$mode" | tee "$EVIDENCE/wire-client.log"
else
  "$python" "$GITHUB_WORKSPACE/qa/workloads/wire-client.py" "$mode" | tee "$EVIDENCE/wire-client.log"
fi
