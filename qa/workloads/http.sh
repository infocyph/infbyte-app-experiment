#!/usr/bin/env bash
set -Eeuo pipefail
unset COMPOSER_NO_DEV
export AUTH_TOKEN_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_RECOVERY_HMAC_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_PROTECTION_KEY="$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");')"
export APP_ENV=production APP_DEBUG=false
export LAB_HTTP_URL=http://127.0.0.1:18781
cd "$PROJECT"
probe="$GITHUB_WORKSPACE/qa/workloads/run.php"
php "$GITHUB_WORKSPACE/qa/workloads/prepare-http.php"
php "$probe" setup
php infbyte config:validate --production
php infbyte optimize --json=1 > "$RUNNER_TEMP/lab-release.json"
php infbyte app:ready
release_root="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); echo $r["release_root"];' "$RUNNER_TEMP/lab-release.json")"
trusted="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); echo $r["manifest_sha256"];' "$RUNNER_TEMP/lab-release.json")"
export INFOCYPH_FOUNDATION_RELEASE_ROOT="$release_root" INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256="$trusted"
host=""
nginx_pid=""
stop_host() {
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
  if [[ "$HOST_MODE" == native ]]; then
    php infbyte serve --runtime=native --workers=4 --host=127.0.0.1 --port=18781 >> "$EVIDENCE/host.log" 2>&1 &
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
  else
    cat "$EVIDENCE/host.log" >&2
  fi
  return 1
}
start_host
total=0
for concurrency in 1 4 8 16; do
  start="$(date +%s%3N)"
  pids=()
  for worker in $(seq 1 "$concurrency"); do
    php "$GITHUB_WORKSPACE/qa/workloads/http-client.php" "http-$concurrency-$worker" 30 > "$EVIDENCE/results-$concurrency-$worker.jsonl" 2> "$EVIDENCE/errors-$concurrency-$worker.log" &
    pids+=("$!")
  done
  failed=0
  for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
  if (( failed )); then cat "$EVIDENCE"/errors-"$concurrency"-*.log >&2; exit 1; fi
  elapsed="$(( $(date +%s%3N) - start ))"
  expected="$((concurrency * 30))"
  php "$GITHUB_WORKSPACE/qa/workloads/report.php" "$expected" "$elapsed" "$concurrency" "$EVIDENCE"/results-"$concurrency"-*.jsonl | tee "$EVIDENCE/http-summary-$concurrency.json"
  total="$((total + expected))"
  php "$probe" verify "$total"
  # Force an independent host generation of processes after the first concurrent phase.
  if (( concurrency == 4 )); then stop_host; start_host; fi
done
if [[ "${SOAK:-0}" == 1 ]]; then
  start="$(date +%s%3N)"
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
  php "$GITHUB_WORKSPACE/qa/workloads/report.php" "$count" "$(( $(date +%s%3N) - start ))" 8 "$EVIDENCE"/soak-*.jsonl | tee "$EVIDENCE/soak-summary.json"
  total="$((total + count))"
fi
stop_host
php "$probe" verify "$total"
printf '%s authorized business cycles (%s total HTTP requests) verified on %s.\n' "$total" "$((total * 5))" "$HOST_MODE" >> "$GITHUB_STEP_SUMMARY"
