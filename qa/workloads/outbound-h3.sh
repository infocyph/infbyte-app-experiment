#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
php -r 'if (!extension_loaded("quic")) {fwrite(STDERR,"Native ext-quic is required.\n");exit(1);}'
root="$(mktemp -d "$RUNNER_TEMP/outbound-h3.XXXXXXXX")"
peer=""
cleanup() {
  if [[ -n "$peer" ]]; then kill "$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; fi
  rm -rf "$root"
}
trap cleanup EXIT
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' -keyout "$root/key.pem" -out "$root/cert.pem" >/dev/null 2>&1
python3 -m venv "$root/venv"
"$root/venv/bin/pip" install 'aioquic>=1.2,<2'
port="$(php -r '$s=stream_socket_server("udp://127.0.0.1:0",$code,$error,STREAM_SERVER_BIND);if (!is_resource($s)) exit(1);$name=stream_socket_get_name($s,false);fclose($s);echo substr(strrchr($name,":"),1);')"
"$root/venv/bin/python" -u "$GITHUB_WORKSPACE/qa/workloads/outbound-h3-peer.py" "$port" "$root/cert.pem" "$root/key.pem" "$root/ready" > "$EVIDENCE/peer.log" 2>&1 &
peer=$!
for i in $(seq 1 100); do
  if [[ -s "$root/ready" ]]; then break; fi
  kill -0 "$peer"
  sleep 0.1
done
test -s "$root/ready"
for mode in client upload multiplex; do
  timeout 30s php "$GITHUB_WORKSPACE/qa/workloads/outbound-h3-$mode.php" "$port" "$root/cert.pem" | tee "$EVIDENCE/$mode.json"
  jq -e '.verified == true' "$EVIDENCE/$mode.json" >/dev/null
done
timeout 30s php "$GITHUB_WORKSPACE/qa/workloads/outbound-h3-multiplex.php" "$port" "$root/cert.pem" head | tee "$EVIDENCE/head.json"
jq -e '.verified and .head_root_and_sibling_verified and .cleanup' "$EVIDENCE/head.json" >/dev/null
grep -q 'independent H3 cancel request admitted' "$EVIDENCE/peer.log"
