#!/usr/bin/env bash
set -Eeuo pipefail
unset COMPOSER_NO_DEV
export AUTH_OTP_RECOVERY_HMAC_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_PROTECTION_KEY="$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");')"
export AUTH_TOKEN_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
cd "$PROJECT"
probe="$GITHUB_WORKSPACE/qa/workloads/run.php"
if [[ "$SCENARIO" == mail ]]; then
  for attempt in $(seq 1 30); do
    if curl --silent --fail --max-time 2 http://127.0.0.1:8025/api/v1/messages > /dev/null; then break; fi
    sleep 1
  done
  curl --silent --fail --max-time 2 http://127.0.0.1:8025/api/v1/messages > /dev/null
fi
php "$probe" setup
total=0
for concurrency in 1 4 8; do
  iterations=40
  if [[ "$SCENARIO" == accounts || "$SCENARIO" == security ]]; then iterations=8; fi
  if [[ "$SCENARIO" == queue ]]; then iterations=20; fi
  start="$(date +%s%3N)"
  pids=()
  for worker in $(seq 1 "$concurrency"); do
    php "$probe" worker "$concurrency-$worker" "$iterations" > "$EVIDENCE/results-$concurrency-$worker.jsonl" 2> "$EVIDENCE/errors-$concurrency-$worker.log" &
    pids+=("$!")
  done
  failed=0
  for pid in "${pids[@]}"; do
    if ! wait "$pid"; then failed=1; fi
  done
  if (( failed )); then
    cat "$EVIDENCE"/errors-"$concurrency"-*.log >&2
    exit 1
  fi
  elapsed="$(( $(date +%s%3N) - start ))"
  expected="$((concurrency * iterations))"
  php "$GITHUB_WORKSPACE/qa/workloads/report.php" "$expected" "$elapsed" "$concurrency" "$EVIDENCE"/results-"$concurrency"-*.jsonl | tee "$EVIDENCE/summary-$concurrency.json"
  total="$((total + expected))"
  if [[ "$SCENARIO" == queue ]]; then
    pids=()
    for consumer in $(seq 1 4); do
      php "$probe" drain > "$EVIDENCE/queue-$concurrency-$consumer.log" 2>&1 &
      pids+=("$!")
    done
    failed=0
    for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
    if (( failed )); then cat "$EVIDENCE"/queue-"$concurrency"-*.log >&2; exit 1; fi
  fi
  php "$probe" verify "$total"
done
printf 'Verified %s completed business operations across concurrency levels 1, 4, 8.\n' "$total" | tee -a "$GITHUB_STEP_SUMMARY"
