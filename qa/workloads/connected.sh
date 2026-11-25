#!/usr/bin/env bash
set -Eeuo pipefail
export AUTH_TOKEN_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
export LAB_CONNECTED_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
export APP_ENV=production APP_DEBUG=false
cd "$PROJECT"
worker="$GITHUB_WORKSPACE/qa/workloads/connected-worker.php"
php "$GITHUB_WORKSPACE/qa/workloads/prepare-http.php"
php "$worker" setup
php infbyte config:validate --production
php infbyte optimize --json=1 > "$RUNNER_TEMP/connected-release.json"
php infbyte app:ready
export INFOCYPH_FOUNDATION_RELEASE_ROOT="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); echo $r["release_root"];' "$RUNNER_TEMP/connected-release.json")"
export INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); echo $r["manifest_sha256"];' "$RUNNER_TEMP/connected-release.json")"
php infbyte serve --runtime=native --workers=4 --host=127.0.0.1 --port=18781 > "$EVIDENCE/connected-host.log" 2>&1 &
host=$!
trap 'kill "$host" 2>/dev/null || :; wait "$host" 2>/dev/null || :' EXIT
url=http://127.0.0.1:18781
ready=0
for attempt in $(seq 1 40); do
  if [[ "$(curl -sSf --max-time 2 "$url/api/health" || :)" == '{"status":"ok"}' ]]; then ready=1; break; fi
  sleep 1
done
(( ready == 1 ))
[[ "$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$url/api/connected/invalid/0")" == 422 ]]
pids=()
for n in $(seq 1 8); do
  (
    for i in $(seq 1 20); do
      request="checkout-$n-$i"
      first="$(curl -fsS --max-time 10 -X POST "$url/api/connected/$request/2")"
      second="$(curl -fsS --max-time 10 -X POST "$url/api/connected/$request/2")"
      php -r '$a=json_decode($argv[1],true,flags:JSON_THROW_ON_ERROR);$b=json_decode($argv[2],true,flags:JSON_THROW_ON_ERROR);if (($a["created"]??null)!==true || ($b["created"]??null)!==false || $a["id"]!==$b["id"] || $a["quantity"]!==2) exit(1); echo $a["id"],PHP_EOL;' "$first" "$second"
    done
  ) > "$EVIDENCE/connected-orders-$n.txt" &
  pids+=("$!")
done
failed=0
for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
(( failed == 0 ))
[[ "$(cat "$EVIDENCE"/connected-orders-*.txt | sort -u | wc -l)" == 160 ]]
[[ "$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$url/api/connected/checkout-1-1/3")" == 409 ]]
expected=160
if [[ "$SCENARIO" == ecosystem-active-recovery ]]; then
  bash "$GITHUB_WORKSPACE/qa/workloads/connected-active-recovery.sh" "$url"
  expected=176
fi
php "$worker" rollback
for i in 1 2 3 4; do php "$worker" relay > "$EVIDENCE/relay-$i.log" 2>&1 & pids[$((i-1))]=$!; done
failed=0
for i in 0 1 2 3; do if ! wait "${pids[$i]}"; then failed=1; fi; done
(( failed == 0 ))
if [[ "$SCENARIO" == ecosystem-recovery || "$SCENARIO" == ecosystem-active-recovery ]]; then
  php "$worker" pending "$expected"
  # Reserve a real message without completing it. Kill the process before external side effects.
  php "$worker" reserve-recovery > "$EVIDENCE/reservation.log" 2>&1 &
  reserved=$!
  for attempt in $(seq 1 100); do
    if [[ -f "$RUNNER_TEMP/connected-reserved" ]]; then break; fi
    kill -0 "$reserved"
    sleep 0.1
  done
  test -f "$RUNNER_TEMP/connected-reserved"
  kill -KILL "$reserved"
  wait "$reserved" 2>/dev/null || :
  bash "$GITHUB_WORKSPACE/qa/workloads/connected-recovery.sh" outage
  php "$worker" pending "$expected"
fi
for i in 1 2 3 4; do php "$worker" drain > "$EVIDENCE/consumer-$i.log" 2>&1 & pids[$((i-1))]=$!; done
failed=0
for i in 0 1 2 3; do if ! wait "${pids[$i]}"; then failed=1; fi; done
(( failed == 0 ))
php "$worker" duplicate "$(head -n 1 "$EVIDENCE/connected-orders-1.txt")"
php "$worker" drain
php "$worker" verify "$expected"
if [[ "$SCENARIO" == ecosystem-recovery || "$SCENARIO" == ecosystem-active-recovery ]]; then bash "$GITHUB_WORKSPACE/qa/workloads/connected-recovery.sh" restore; fi
echo "$expected unique HTTP orders, replay requests, transactional outbox rollback/relay, four durable consumers, encrypted files, duplicate delivery and exact SMTP reconciliation." >> "$GITHUB_STEP_SUMMARY"
