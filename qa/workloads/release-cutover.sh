#!/usr/bin/env bash
set -Eeuo pipefail
export APP_ENV=production APP_DEBUG=false
export AUTH_TOKEN_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
cd "$PROJECT"
admin="$GITHUB_WORKSPACE/qa/workloads/release-admin.php"
php "$GITHUB_WORKSPACE/qa/workloads/prepare-http.php"
php "$admin" setup
php infbyte config:validate --production
php infbyte optimize --json=1 > "$RUNNER_TEMP/release-old.json"
php infbyte app:ready
declare -a hosts=()
cleanup() {
  for pid in "${hosts[@]}"; do kill -TERM -- "-$pid" 2>/dev/null || :; done
  for pid in "${hosts[@]}"; do wait "$pid" 2>/dev/null || :; done
}
trap cleanup EXIT
start() {
  local name="$1" port="$2" file="$RUNNER_TEMP/release-$1.json"
  local root digest
  root="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);echo $r["release_root"];' "$file")"
  digest="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);echo $r["manifest_sha256"];' "$file")"
  INFOCYPH_FOUNDATION_RELEASE_ROOT="$root" INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256="$digest" setsid php infbyte serve --runtime=native --workers=2 --host=127.0.0.1 --port="$port" > "$EVIDENCE/release-$name-$port.log" 2>&1 &
  hosts+=("$!")
  for i in $(seq 1 40); do
    if curl -fsS --max-time 2 "http://127.0.0.1:$port/api/lab/release" >/dev/null; then return; fi
    sleep 1
  done
  return 1
}
request() {
  local port="$1" expected="$2"
  [[ "$(curl -fsS --max-time 5 "http://127.0.0.1:$port/api/lab/release")" == "$expected" ]]
}
old='{"generation":"old","value":42}'
next='{"generation":"next","value":42,"revision":1}'
start old 18781
request 18781 "$old"
# Keep a real old-generation traffic stream alive across DDL and publication.
(
  for i in $(seq 1 1000); do request 18781 "$old"; sleep 0.02; done
) > "$EVIDENCE/old-traffic.log" 2>&1 &
traffic=$!
php "$admin" migrate
sed -i "s/ReleaseProbe::class, 'old'/ReleaseProbe::class, 'next'/" routes/api.php
php infbyte optimize --json=1 > "$RUNNER_TEMP/release-next.json"
php infbyte app:ready
php "$admin" reject "$RUNNER_TEMP/release-next.json"
start next 18782
request 18781 "$old"
request 18782 "$next"
php "$admin" activate "$RUNNER_TEMP/release-old.json"
start old 18783
request 18783 "$old"
# New workers already serving must not silently change generation when the pointer rolls back.
request 18782 "$next"
wait "$traffic"
for i in $(seq 1 50); do
  request 18781 "$old"
  request 18782 "$next"
  request 18783 "$old"
done
echo 'Live old/new releases, additive schema migration, trusted digest rejection, pointer rollback and 1150 old/new requests reconciled.' >> "$GITHUB_STEP_SUMMARY"
