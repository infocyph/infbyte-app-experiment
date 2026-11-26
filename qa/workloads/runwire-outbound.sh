#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
root="$(mktemp -d "$RUNNER_TEMP/runwire-outbound.XXXXXXXX")"
peer=""
cleanup() {
  if [[ -n "$peer" ]]; then kill "$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; fi
  if [[ -f "$root/requests.jsonl" ]]; then
    cp "$root/requests.jsonl" "$EVIDENCE/runwire-outbound-requests.jsonl"
  fi
  rm -rf "$root"
}
trap cleanup EXIT
negotiation=0
if [[ "$SCENARIO" == ecosystem-runwire-negotiation-* ]]; then
  negotiation=1
  mode="${SCENARIO#ecosystem-runwire-negotiation-}"
else
  mode="${SCENARIO#ecosystem-runwire-outbound-}"
fi
protocol="${mode%%-*}"
contract="${mode#*-}"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=127.0.0.1' -addext 'subjectAltName=IP:127.0.0.1' -keyout "$root/key.pem" -out "$root/cert.pem" >/dev/null 2>&1
python3 -m venv "$root/venv"
python="$root/venv/bin/python"
"$python" -m pip install 'h2>=4,<5'
"$python" "$GITHUB_WORKSPACE/qa/workloads/runwire-outbound-peer.py" "$root" "$protocol" > "$EVIDENCE/runwire-outbound-peer.log" 2>&1 &
peer=$!
for attempt in {1..100}; do
  [[ -s "$root/port" ]] && break
  kill -0 "$peer"
  sleep 0.1
done
test -s "$root/port"
export LAB_RUNWIRE_OUTBOUND_PORT="$(cat "$root/port")"
export LAB_RUNWIRE_OUTBOUND_CA="$root/cert.pem"
if ((negotiation)); then
  openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=wrong-fixture" -keyout "$root/wrong-key.pem" -out "$root/wrong-cert.pem" >/dev/null 2>&1
  export LAB_RUNWIRE_WRONG_CA="$root/wrong-cert.pem"
fi
timeout 45s php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/runwire-outbound.log"
cp "$root/requests.jsonl" "$EVIDENCE/runwire-outbound-requests.jsonl"
if ((negotiation)); then
  php -r '$rows=array_map(fn($s)=>json_decode($s,true,flags:JSON_THROW_ON_ERROR),file($argv[1],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));$alpn=$argv[2]==="h1"?"http/1.1":"h2";$expected=[["path"=>"/recovery","method"=>"GET","alpn"=>$alpn]];if(in_array($argv[3],["strict","preferred-fallback","reverse-fallback"],true))array_unshift($expected,["path"=>"/get","method"=>"GET","alpn"=>$alpn]);if($rows!==$expected)exit(1);' "$EVIDENCE/runwire-outbound-requests.jsonl" "$protocol" "$contract"
  exit 0
fi
php -r '$rows=array_map(fn($s)=>json_decode($s,true,flags:JSON_THROW_ON_ERROR),file($argv[1],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));$alpn=$argv[2]==="h1"?"http/1.1":"h2";$expected=[["path"=>"/".$argv[3],"method"=>$argv[3]==="head"?"HEAD":"GET","alpn"=>$alpn],["path"=>"/recovery","method"=>"GET","alpn"=>$alpn]];if($rows!==$expected)exit(1);' "$EVIDENCE/runwire-outbound-requests.jsonl" "$protocol" "$contract"
