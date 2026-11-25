#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
root="$(mktemp -d "$RUNNER_TEMP/http-policy.XXXXXXXX")"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' -keyout "$root/key.pem" -out "$root/cert.pem" >/dev/null 2>&1
export LAB_HTTP_CA="$root/cert.pem"
export LAB_HTTP_ROOT="$root"
python3 "$GITHUB_WORKSPACE/qa/workloads/http-policy-peer.py" "$root" > "$EVIDENCE/http-peer.log" 2>&1 &
peer=$!
trap 'kill "$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; rm -rf "$root"' EXIT
for i in $(seq 1 100); do
  if [[ -s "$root/ready.json" ]]; then break; fi
  kill -0 "$peer"
  sleep 0.1
done
export LAB_HTTP_PORT="$(php -r 'echo json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR)["port"];' "$root/ready.json")"
export LAB_HTTP_OTHER="$(php -r 'echo json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR)["other"];' "$root/ready.json")"
if [[ "$SCENARIO" == ecosystem-http-proxy || "$SCENARIO" == ecosystem-http-proxy-retry-* ]]; then
  export LAB_HTTP_PROXY=19082
  unset NO_PROXY no_proxy
  python3 "$GITHUB_WORKSPACE/qa/workloads/http-connect-peer.py" "$root" "$LAB_HTTP_PORT" "$LAB_HTTP_PROXY" > "$EVIDENCE/proxy-peer.log" 2>&1 &
  proxy_peer=$!
  trap 'kill "$proxy_peer" "$peer" 2>/dev/null || :; wait "$proxy_peer" "$peer" 2>/dev/null || :; rm -rf "$root"' EXIT
  for attempt in {1..100}; do
    [[ -f "$root/proxy-counts.json" ]] && break
    kill -0 "$proxy_peer"
    sleep 0.1
  done
  test -f "$root/proxy-counts.json"
fi
php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log"
