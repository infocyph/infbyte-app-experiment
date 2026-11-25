#!/usr/bin/env bash
set -Eeuo pipefail
export AUTH_TOKEN_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
export APP_ENV=production APP_DEBUG=false
case "${LAB_PROFILE:-smoke}" in
  smoke) seconds=10; concurrency=8 ;;
  stress) seconds=45; concurrency=32 ;;
  soak) seconds=450; concurrency=16 ;;
  endurance) seconds=1800; concurrency=16 ;;
  *) echo 'Invalid workload profile' >&2; exit 1 ;;
esac
cd "$PROJECT"
admin="$GITHUB_WORKSPACE/qa/workloads/endurance-admin.php"
client="$GITHUB_WORKSPACE/qa/workloads/endurance-client.php"
php "$GITHUB_WORKSPACE/qa/workloads/prepare-http.php"
php "$admin" setup
php infbyte config:validate --production
php infbyte optimize --json=1 > "$RUNNER_TEMP/endurance-release.json"
php infbyte app:ready
export INFOCYPH_FOUNDATION_RELEASE_ROOT="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); echo $r["release_root"];' "$RUNNER_TEMP/endurance-release.json")"
export INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); echo $r["manifest_sha256"];' "$RUNNER_TEMP/endurance-release.json")"
host=""
monitor=""
stop_host() {
  if [[ -n "$monitor" ]]; then kill "$monitor" 2>/dev/null || :; wait "$monitor" 2>/dev/null || :; monitor=""; fi
  if [[ -n "$host" ]]; then
    kill -TERM -- "-$host" 2>/dev/null || :
    wait "$host" 2>/dev/null || :
    kill -KILL -- "-$host" 2>/dev/null || :
    host=""
  fi
}
trap stop_host EXIT
start_host() {
  setsid php infbyte serve --runtime=native --workers=4 --host=127.0.0.1 --port=18783 >> "$EVIDENCE/host.log" 2>&1 &
  host=$!
  for i in $(seq 1 40); do
    if [[ "$(curl -sSf --max-time 2 http://127.0.0.1:18783/api/health || :)" == '{"status":"ok"}' ]]; then return; fi
    kill -0 "$host"
    sleep 1
  done
  return 1
}
phase() {
  local number="$1" workers="$2" duration="$3" maximum="$4"
  local start elapsed expected failed pid
  local pids=()
  start="$(php -r 'echo intdiv(hrtime(true), 1000000);')"
  (
    while kill -0 "$host" 2>/dev/null; do
      date -u +%FT%TZ
      ps -eo pid,ppid,pgid,rss,etimes,comm | awk -v group="$host" 'NR==1 || $3==group'
      sleep 5
    done
  ) >> "$EVIDENCE/process-memory.txt" &
  monitor=$!
  for worker in $(seq 1 "$workers"); do
    php "$client" "$number" "$worker" "$duration" "$maximum" > "$EVIDENCE/phase-$number-$worker.jsonl" 2> "$EVIDENCE/error-$number-$worker.log" &
    pids+=("$!")
  done
  failed=0
  for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
  kill "$monitor" 2>/dev/null || :
  wait "$monitor" 2>/dev/null || :
  monitor=""
  if (( failed )); then cat "$EVIDENCE"/error-"$number"-*.log >&2; return 1; fi
  for worker in $(seq 1 "$workers"); do test -s "$EVIDENCE/phase-$number-$worker.jsonl"; done
  elapsed="$(( $(php -r 'echo intdiv(hrtime(true), 1000000);') - start ))"
  expected="$(cat "$EVIDENCE"/phase-"$number"-*.jsonl | wc -l)"
  if (( number == 0 )); then test "$expected" = "$((workers * maximum))"; fi
  if (( number != 0 )); then test "$elapsed" -ge "$((duration * 1000 - 1000))"; fi
  php "$GITHUB_WORKSPACE/qa/workloads/report.php" "$expected" "$elapsed" "$workers" "$EVIDENCE"/phase-"$number"-*.jsonl | tee "$EVIDENCE/summary-$number.json"
}
start_host
# Fixed 64-client spike. Every expected cycle must complete.
phase 0 64 120 4
phase 1 "$concurrency" "$seconds" 100000
# Cut over during a known durable commit before its response/cache publication.
url=http://127.0.0.1:18783/api/endurance/1/chaos-commit
curl -fsS --max-time 20 -X POST "$url" > "$RUNNER_TEMP/chaos-response" 2> "$EVIDENCE/chaos-client.log" &
uncertain=$!
php "$admin" await-commit
kill -KILL -- "-$host"
wait "$host" 2>/dev/null || :
host=""
status=0
wait "$uncertain" || status=$?
test "$status" != 0
start_host
curl -fsS --max-time 10 -X POST "$url" | php -r '$r=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR); if ($r!==["id"=>"chaos-commit","tenant"=>1,"created"=>false]) exit(1);'
phase 2 "$concurrency" "$seconds" 100000
stop_host
start_host
phase 3 "$concurrency" "$seconds" 100000
phase 4 "$concurrency" "$seconds" 100000
stop_host
php "$admin" reconcile > "$RUNNER_TEMP/ledger.txt"
{
  printf 'chaos-commit:1\n'
  php -r 'foreach (array_slice($argv,1) as $path) { $fp=fopen($path,"rb"); while (($line=fgets($fp))!==false) { $r=json_decode($line,true,flags:JSON_THROW_ON_ERROR); echo $r["id"],":",$r["tenant"],PHP_EOL; } fclose($fp); }' "$EVIDENCE"/phase-*.jsonl
} | LC_ALL=C sort > "$RUNNER_TEMP/expected.txt"
LC_ALL=C sort "$RUNNER_TEMP/ledger.txt" > "$RUNNER_TEMP/actual.txt"
cmp "$RUNNER_TEMP/expected.txt" "$RUNNER_TEMP/actual.txt"
sha256sum "$RUNNER_TEMP/actual.txt" | cut -d' ' -f1 > "$EVIDENCE/ledger-sha256.txt"
wc -l < "$RUNNER_TEMP/actual.txt" > "$EVIDENCE/ledger-count.txt"
echo "Exact durable ledger/cache/tenant reconciliation after spike, sustained phases and uncertain response crash." >> "$GITHUB_STEP_SUMMARY"
