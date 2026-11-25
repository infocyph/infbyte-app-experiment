#!/usr/bin/env bash
set -Eeuo pipefail
unset COMPOSER_NO_DEV
case "$SCENARIO" in
  browser-policy-cors|browser-policy-representation)
    export LAB_BROWSER_POLICY="${SCENARIO#browser-policy-}"
    export SCENARIO=browser
    ;;
  browser-file|browser-database)
    export LAB_SESSION_DRIVER="${SCENARIO#browser-}"
    export SCENARIO=browser
    ;;
esac
export AUTH_TOKEN_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_RECOVERY_HMAC_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_PROTECTION_KEY="$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");')"
export APP_ENV=production APP_DEBUG=false
export LAB_HTTP_URL=http://127.0.0.1:18781
cd "$PROJECT"
probe="$GITHUB_WORKSPACE/qa/workloads/run.php"
if [[ "$SCENARIO" == oauth-http ]]; then
  umask 077
  for purpose in oauth oidc; do
    mkdir -p "$PROJECT/storage/lab-$purpose-keys"
    openssl ecparam -name prime256v1 -genkey -noout -out "$PROJECT/storage/lab-$purpose-keys/private.pem"
    openssl ec -in "$PROJECT/storage/lab-$purpose-keys/private.pem" -pubout -out "$PROJECT/storage/lab-$purpose-keys/public.pem"
  done
fi
php "$GITHUB_WORKSPACE/qa/workloads/prepare-http.php"
if [[ "$SCENARIO" == browser ]]; then
  if [[ -n "${LAB_SESSION_DRIVER:-}" ]]; then
    php "$GITHUB_WORKSPACE/qa/workloads/browser-storage-probe.php" install
  fi
  mkdir -p "$PROJECT/storage/lab-public"
  php -r 'file_put_contents($argv[1],str_repeat("0123456789abcdef",8192),LOCK_EX);' "$PROJECT/storage/lab-public/report.bin"
elif [[ "$SCENARIO" == oauth-http ]]; then
  php "$GITHUB_WORKSPACE/qa/workloads/oauth-http-seed.php"
elif [[ "$SCENARIO" == transfer || "$SCENARIO" == transfer-cleanup ]]; then
  mkdir -m 700 "$PROJECT/storage/lab-transfers"
else
  php "$probe" setup
fi
php infbyte config:validate --production
php infbyte optimize --json=1 > "$RUNNER_TEMP/lab-release.json"
php infbyte app:ready
release_root="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); echo $r["release_root"];' "$RUNNER_TEMP/lab-release.json")"
trusted="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); echo $r["manifest_sha256"];' "$RUNNER_TEMP/lab-release.json")"
export INFOCYPH_FOUNDATION_RELEASE_ROOT="$release_root" INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256="$trusted"
host=""
nginx_pid=""
stop_host() {
  if [[ "$HOST_MODE" == frankenphp ]]; then
    docker logs infbyte-lab-frankenphp >> "$EVIDENCE/host.log" 2>&1 || :
    docker rm -f infbyte-lab-frankenphp >/dev/null 2>&1 || :
    return
  fi
  if [[ "$HOST_MODE" == fpm ]]; then
    docker logs infbyte-lab-fpm >> "$EVIDENCE/fpm.log" 2>&1 || :
    docker logs infbyte-lab-nginx >> "$EVIDENCE/nginx.log" 2>&1 || :
    docker rm -f infbyte-lab-nginx infbyte-lab-fpm >/dev/null 2>&1 || :
    return
  fi
  if [[ -n "$nginx_pid" ]]; then kill "$nginx_pid" 2>/dev/null || :; wait "$nginx_pid" 2>/dev/null || :; nginx_pid=""; fi
  if [[ -n "$host" ]]; then kill "$host" 2>/dev/null || :; wait "$host" 2>/dev/null || :; host=""; fi
}
trap stop_host EXIT
start_host() {
  if [[ "$HOST_MODE" == roadrunner || "$HOST_MODE" == frankenphp ]]; then
    bash "$GITHUB_WORKSPACE/qa/workloads/host-config.sh"
    if [[ "$HOST_MODE" == roadrunner ]]; then
      "$RUNNER_TEMP/lab-rr" serve -c "$RUNNER_TEMP/lab-rr.yaml" >> "$EVIDENCE/host.log" 2>&1 &
      host=$!
    else
      docker run -d --name infbyte-lab-frankenphp --network host --user "$(id -u):$(id -g)" \
        -v "$PROJECT:$PROJECT" -v "$RUNNER_TEMP/lab-caddyfile:/tmp/lab-caddyfile:ro" -w "$PROJECT" \
        -e APP_ENV -e APP_DEBUG -e AUTH_TOKEN_SECRET -e AUTH_OTP_RECOVERY_HMAC_KEY -e AUTH_OTP_PROTECTION_KEY \
        -e INFOCYPH_FOUNDATION_RELEASE_ROOT -e INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256 \
        -e XDG_CONFIG_HOME=/tmp -e XDG_DATA_HOME=/tmp \
        --entrypoint /usr/local/bin/frankenphp infbyte-lab-frankenphp run --config /tmp/lab-caddyfile --adapter caddyfile
    fi
  elif [[ "$HOST_MODE" != fpm ]]; then
    local runtime=native
    case "$HOST_MODE" in
      native) ;;
      swoole) php -r 'exit(extension_loaded("swoole") ? 0 : 1);'; runtime=swoole ;;
      openswoole) php -r 'exit(extension_loaded("openswoole") ? 0 : 1);'; runtime=swoole ;;
      *) echo "Unsupported host: $HOST_MODE" >&2; return 1 ;;
    esac
    php infbyte serve --runtime="$runtime" --workers=4 --host=127.0.0.1 --port=18781 >> "$EVIDENCE/host.log" 2>&1 &
    host=$!
  else
    cat > "$RUNNER_TEMP/lab-fpm.conf" <<CONF
[global]
daemonize = no
error_log = /proc/self/fd/2
[lab]
listen = 127.0.0.1:19000
pm = static
pm.max_children = 4
clear_env = no
catch_workers_output = yes
CONF
    cat > "$RUNNER_TEMP/lab-nginx.conf" <<CONF
events { worker_connections 1024; }
http {
  access_log off;
  server {
    listen 127.0.0.1:18781;
    location / {
      include /etc/nginx/fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $PROJECT/public/index.php;
      fastcgi_param SCRIPT_NAME /index.php;
      fastcgi_param HTTP_AUTHORIZATION \$http_authorization;
      fastcgi_param INFOCYPH_FOUNDATION_RELEASE_ROOT $release_root;
      fastcgi_param INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256 $trusted;
      fastcgi_pass 127.0.0.1:19000;
    }
  }
}
CONF
    docker run -d --name infbyte-lab-fpm --network host --user "$(id -u):$(id -g)" \
      -v "$PROJECT:$PROJECT" -v "$RUNNER_TEMP/lab-fpm.conf:/tmp/lab-fpm.conf:ro" -w "$PROJECT" \
      -e APP_ENV -e APP_DEBUG -e AUTH_TOKEN_SECRET -e AUTH_OTP_RECOVERY_HMAC_KEY -e AUTH_OTP_PROTECTION_KEY \
      -e INFOCYPH_FOUNDATION_RELEASE_ROOT -e INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256 \
      infbyte-lab-fpm php-fpm -F -y /tmp/lab-fpm.conf
    docker run -d --name infbyte-lab-nginx --network host \
      -v "$RUNNER_TEMP/lab-nginx.conf:/etc/nginx/nginx.conf:ro" nginx:alpine
  fi
  for attempt in $(seq 1 40); do
    if [[ "$(curl --silent --fail --max-time 2 "$LAB_HTTP_URL/api/health" || :)" == '{"status":"ok"}' ]]; then return; fi
    sleep 1
  done
  if [[ "$HOST_MODE" == fpm ]]; then
    docker logs infbyte-lab-fpm >&2 || :
    docker logs infbyte-lab-nginx >&2 || :
  elif [[ "$HOST_MODE" == frankenphp ]]; then
    docker logs infbyte-lab-frankenphp >&2 || :
  else
    cat "$EVIDENCE/host.log" >&2
  fi
  return 1
}
start_host
if [[ "$SCENARIO" == oauth-http ]]; then
  php "$GITHUB_WORKSPACE/qa/workloads/oauth-http-client.php" seed | tee "$EVIDENCE/oauth-http-seed.log"
  stop_host
  start_host
  php "$GITHUB_WORKSPACE/qa/workloads/oauth-http-client.php" recover | tee "$EVIDENCE/oauth-http-recover.log"
  rm -f "$RUNNER_TEMP/oauth-http-fixture.json"
  exit 0
fi
if [[ "$SCENARIO" == transfer-cleanup ]]; then
  client="$GITHUB_WORKSPACE/qa/workloads/transfer-cleanup-client.php"
  php "$client" | tee "$EVIDENCE/stream-cleanup.log"
  pids=()
  for worker in {1..4}; do
    php "$client" parallel > "$EVIDENCE/stream-cleanup-parallel-$worker.jsonl" 2> "$EVIDENCE/stream-cleanup-parallel-$worker.log" &
    pids+=("$!")
  done
  failed=0
  for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
  if (( failed )); then cat "$EVIDENCE"/stream-cleanup-parallel-*.log >&2; exit 1; fi
  test -z "$(find "$PROJECT/storage/lab-transfers" -mindepth 1 -print -quit)"
  stop_host
  start_host
  php "$client" | tee "$EVIDENCE/stream-cleanup-replacement.log"
  exit 0
fi
if [[ "$SCENARIO" == transfer ]]; then
  php "$GITHUB_WORKSPACE/qa/workloads/transfer-client.php" | tee "$EVIDENCE/transfer.log"
  stop_host
  start_host
  php "$GITHUB_WORKSPACE/qa/workloads/transfer-client.php" | tee "$EVIDENCE/transfer-replacement.log"
  exit 0
fi
if [[ "$SCENARIO" == browser && -n "${LAB_BROWSER_POLICY:-}" ]]; then
  client="$GITHUB_WORKSPACE/qa/workloads/browser-policy-client.php"
  php "$client" | tee "$EVIDENCE/browser-policy.log"
  pids=()
  for worker in {1..4}; do
    php "$client" > "$EVIDENCE/browser-policy-$worker.json" 2> "$EVIDENCE/browser-policy-$worker.log" &
    pids+=("$!")
  done
  failed=0
  for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
  if (( failed )); then cat "$EVIDENCE"/browser-policy-*.log >&2; exit 1; fi
  stop_host
  start_host
  php "$client" | tee "$EVIDENCE/browser-policy-replacement.log"
  exit 0
fi
if [[ "$SCENARIO" == browser ]]; then
  client="$GITHUB_WORKSPACE/qa/workloads/browser-client.php"
  umask 077
  php "$client" initialize
  pids=()
  start="$(php -r 'echo intdiv(hrtime(true), 1000000);')"
  for worker in $(seq 1 8); do
    php "$client" worker "$worker" 25 > "$EVIDENCE/browser-$worker.jsonl" 2> "$EVIDENCE/browser-error-$worker.log" &
    pids+=("$!")
  done
  failed=0
  for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
  if (( failed )); then cat "$EVIDENCE"/browser-error-*.log >&2; exit 1; fi
  php "$GITHUB_WORKSPACE/qa/workloads/report.php" 200 "$(( $(php -r 'echo intdiv(hrtime(true), 1000000);') - start ))" 8 "$EVIDENCE"/browser-*.jsonl | tee "$EVIDENCE/browser-summary.json"
  stop_host
  if [[ -n "${LAB_SESSION_DRIVER:-}" ]]; then
    php "$GITHUB_WORKSPACE/qa/workloads/browser-storage-probe.php" observe 200 | tee "$EVIDENCE/browser-storage.json"
  fi
  start_host
  php "$client" verify 200
  stop_host
  echo "Browser session lock, CSRF, rotation, flash, HTTP semantics, range and stream verified after replacement." >> "$GITHUB_STEP_SUMMARY"
  exit 0
fi
total=0
for concurrency in 1 4 8 16; do
  start="$(php -r 'echo intdiv(hrtime(true), 1000000);')"
  pids=()
  for worker in $(seq 1 "$concurrency"); do
    php "$GITHUB_WORKSPACE/qa/workloads/http-client.php" "http-$concurrency-$worker" 30 > "$EVIDENCE/results-$concurrency-$worker.jsonl" 2> "$EVIDENCE/errors-$concurrency-$worker.log" &
    pids+=("$!")
  done
  failed=0
  for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
  if (( failed )); then cat "$EVIDENCE"/errors-"$concurrency"-*.log >&2; exit 1; fi
  elapsed="$(( $(php -r 'echo intdiv(hrtime(true), 1000000);') - start ))"
  expected="$((concurrency * 30))"
  php "$GITHUB_WORKSPACE/qa/workloads/report.php" "$expected" "$elapsed" "$concurrency" "$EVIDENCE"/results-"$concurrency"-*.jsonl | tee "$EVIDENCE/http-summary-$concurrency.json"
  total="$((total + expected))"
  php "$probe" verify "$total"
  # Force an independent host generation of processes after the first concurrent phase.
  if (( concurrency == 4 )); then stop_host; start_host; fi
done
if [[ "${SOAK:-0}" == 1 ]]; then
  start="$(php -r 'echo intdiv(hrtime(true), 1000000);')"
  pids=()
  for worker in $(seq 1 8); do
    php "$GITHUB_WORKSPACE/qa/workloads/http-client.php" "soak-$worker" 0 90 > "$EVIDENCE/soak-$worker.jsonl" 2> "$EVIDENCE/soak-error-$worker.log" &
    pids+=("$!")
  done
  (
    while kill -0 "${pids[0]}" 2>/dev/null; do
      date -u +%FT%TZ
      ps -o pid,ppid,rss,etimes,comm -p "$host" --ppid "$host" || :
      sleep 10
    done
  ) > "$EVIDENCE/soak-process-memory.txt" &
  monitor=$!
  failed=0
  for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
  wait "$monitor"
  if (( failed )); then cat "$EVIDENCE"/soak-error-*.log >&2; exit 1; fi
  count="$(cat "$EVIDENCE"/soak-*.jsonl | wc -l)"
  php "$GITHUB_WORKSPACE/qa/workloads/report.php" "$count" "$(( $(php -r 'echo intdiv(hrtime(true), 1000000);') - start ))" 8 "$EVIDENCE"/soak-*.jsonl | tee "$EVIDENCE/soak-summary.json"
  total="$((total + count))"
fi
stop_host
php "$probe" verify "$total"
printf '%s authorized business cycles (%s total HTTP requests) verified on %s.\n' "$total" "$((total * 5))" "$HOST_MODE" >> "$GITHUB_STEP_SUMMARY"
