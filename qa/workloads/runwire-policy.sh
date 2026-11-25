#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
root="$(mktemp -d "$RUNNER_TEMP/runwire-policy.XXXXXXXX")"
peer=""
cleanup() {
  if [[ -n "$peer" ]]; then kill "$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; fi
  rm -rf "$root"
}
trap cleanup EXIT
contract="${SCENARIO#ecosystem-runwire-h1-policy-}"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=127.0.0.1' -addext 'subjectAltName=IP:127.0.0.1' -keyout "$root/key.pem" -out "$root/cert.pem" >/dev/null 2>&1
python3 "$GITHUB_WORKSPACE/qa/workloads/runwire-policy-peer.py" "$root" > "$EVIDENCE/runwire-policy-peer.log" 2>&1 &
peer=$!
for attempt in {1..100}; do
  [[ -s "$root/ports" ]] && break
  kill -0 "$peer"
  sleep 0.1
done
read -r LAB_POLICY_PORT LAB_POLICY_TLS_PORT < "$root/ports"
export LAB_POLICY_PORT LAB_POLICY_TLS_PORT
export LAB_POLICY_CA="$root/cert.pem"
timeout 45s php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/runwire-policy.log"
cp "$root/requests.jsonl" "$EVIDENCE/runwire-policy-requests.jsonl"
php -r '
$case=$argv[2];$rows=array_map(fn($s)=>json_decode($s,true,flags:JSON_THROW_ON_ERROR),file($argv[1],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
$method=str_ends_with($case,"-head")?"HEAD":(in_array($case,["unsafe-post","retry-post"],true)?"POST":"GET");
$primary=fn($path)=>["origin"=>"primary","path"=>$path,"method"=>$method,"auth"=>true,"cookie"=>true,"tenant"=>true,"accept"=>true];
$expected=match($case){
"query"=>[$primary("/query?seed=1"),$primary("/query?next=https://example.test/path")],
"relative"=>[$primary("/relative/base/start"),$primary("/relative/target?value=1")],
"cross-allow"=>[$primary("/cross-allow"),["origin"=>"other","path"=>"/target","method"=>"GET","auth"=>false,"cookie"=>false,"tenant"=>false,"accept"=>true]],
"loop","retry-success","retry-exhausted"=>[$primary("/".$case),$primary("/".$case)],
"cross-deny","unsafe-post","ambiguous","downgrade","retry-post","retry-after-head"=>[$primary("/".$case)],
default=>[$primary("/".$case),$primary("/target")]};
if($rows!==$expected){fwrite(STDERR,json_encode(["actual"=>$rows,"expected"=>$expected],JSON_THROW_ON_ERROR));exit(1);}' "$EVIDENCE/runwire-policy-requests.jsonl" "$contract"
