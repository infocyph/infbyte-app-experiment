#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
root="$(mktemp -d "$RUNNER_TEMP/runwire-multiplex.XXXXXXXX")"
peer=""
cleanup() {
  if [[ -n "$peer" ]]; then kill "$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; fi
  if [[ -f "$root/events.jsonl" ]]; then
    cp "$root/events.jsonl" "$EVIDENCE/runwire-multiplex-events.jsonl"
  fi
  rm -rf "$root"
}
trap cleanup EXIT
mode="${SCENARIO#ecosystem-runwire-multiplex-}"
contract="${mode%-*}"
count="${mode##*-}"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=127.0.0.1' -addext 'subjectAltName=IP:127.0.0.1' -keyout "$root/key.pem" -out "$root/cert.pem" >/dev/null 2>&1
python3 -m venv "$root/venv"
python="$root/venv/bin/python"
"$python" -m pip install 'h2>=4,<5'
"$python" "$GITHUB_WORKSPACE/qa/workloads/runwire-multiplex-peer.py" "$root" "$contract" "$count" > "$EVIDENCE/runwire-multiplex-peer.log" 2>&1 &
peer=$!
for attempt in {1..100}; do
  [[ -s "$root/port" ]] && break
  kill -0 "$peer"
  sleep 0.1
done
test -s "$root/port"
export LAB_RUNWIRE_OUTBOUND_PORT="$(cat "$root/port")"
export LAB_RUNWIRE_OUTBOUND_CA="$root/cert.pem"
timeout 45s php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/runwire-multiplex.log"
cp "$root/events.jsonl" "$EVIDENCE/runwire-multiplex-events.jsonl"
php -r '
$rows=array_map(fn($s)=>json_decode($s,true,flags:JSON_THROW_ON_ERROR),file($argv[1],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
$kind=fn($name)=>array_values(array_filter($rows,fn($row)=>$row["kind"]===$name));
$count=(int)$argv[2];$contract=$argv[3];$expected=[];
for($i=1;$i<=$count;$i++)$expected[]=["kind"=>"request","stream"=>2*$i-1,"path"=>"/stream/".$i,"method"=>$contract==="head"&&$i%2===1?"HEAD":"GET"];
if($contract!=="goaway")$expected[]=["kind"=>"request","stream"=>2*$count+1,"path"=>"/recovery","method"=>"GET"];
if($kind("connection")!==[["kind"=>"connection","alpn"=>"h2"]]||$kind("request")!==$expected||$kind("recovered")!==($contract==="goaway"?[]:[["kind"=>"recovered","stream"=>2*$count+1]]))exit(1);
foreach(["reset","cancel"] as $fault)if($kind($fault)!==(($contract===$fault||($contract==="deadline"&&$fault==="cancel"))?[["kind"=>$fault,"stream"=>1]]:[]))exit(1);
if($contract==="ping"&&($kind("ping")!==[["kind"=>"ping"]]||$kind("ping-ack")!==[["kind"=>"ping-ack"]]))exit(1);
if($contract==="goaway"&&$kind("goaway")!==[["kind"=>"goaway","last"=>2*$count-3]])exit(1);
if($contract==="flow"&&$kind("window-block")===[])exit(1);
' "$EVIDENCE/runwire-multiplex-events.jsonl" "$count" "$contract"
