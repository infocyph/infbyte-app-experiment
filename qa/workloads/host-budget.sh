#!/usr/bin/env bash
set -Eeuo pipefail
cd "$PROJECT"
mkdir -m 700 -p storage/lab-host-budget
cp lab/host-budget-peer.php public/index.php
host=
cleanup() {
  case "$HOST_MODE" in
    fpm)
      docker logs infbyte-budget-fpm > "$EVIDENCE/host-budget-fpm.log" 2>&1 || :
      docker logs infbyte-budget-nginx > "$EVIDENCE/host-budget-nginx.log" 2>&1 || :
      docker rm -f infbyte-budget-fpm infbyte-budget-nginx >/dev/null 2>&1 || : ;;
    frankenphp)
      docker logs infbyte-budget-frankenphp > "$EVIDENCE/host-budget-frankenphp.log" 2>&1 || :
      docker rm -f infbyte-budget-frankenphp >/dev/null 2>&1 || : ;;
    *) if [[ -n "$host" ]]; then kill -TERM -- "-$host" 2>/dev/null || :; wait "$host" 2>/dev/null || :; fi ;;
  esac
}
trap cleanup EXIT
case "$HOST_MODE" in
  fpm)
    cat > "$RUNNER_TEMP/budget-fpm.conf" <<CONF
[global]
daemonize = no
error_log = /proc/self/fd/2
[lab]
listen = 127.0.0.1:19000
pm = static
pm.max_children = 2
clear_env = no
catch_workers_output = yes
CONF
    cat > "$RUNNER_TEMP/budget-nginx.conf" <<CONF
events { worker_connections 1024; }
http {
  access_log off;
  server {
    listen 127.0.0.1:18781;
    location / {
      include /etc/nginx/fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $PROJECT/public/index.php;
      fastcgi_param SCRIPT_NAME /index.php;
      fastcgi_pass 127.0.0.1:19000;
    }
  }
}
CONF
    docker run -d --name infbyte-budget-fpm --network host --user "$(id -u):$(id -g)" \
      -v "$PROJECT:$PROJECT" -v "$RUNNER_TEMP/budget-fpm.conf:/tmp/budget-fpm.conf:ro" -w "$PROJECT" \
      infbyte-lab-fpm php-fpm -F -y /tmp/budget-fpm.conf >/dev/null
    docker run -d --name infbyte-budget-nginx --network host \
      -v "$RUNNER_TEMP/budget-nginx.conf:/etc/nginx/nginx.conf:ro" nginx:alpine >/dev/null ;;
  frankenphp)
    cat > "$RUNNER_TEMP/budget-caddyfile" <<CONF
{
  auto_https off
  admin off
  frankenphp {
    worker $PROJECT/public/index.php 2
  }
}
http://127.0.0.1:18781 {
  root * $PROJECT/public
  php_server
}
CONF
    docker run -d --name infbyte-budget-frankenphp --network host --user "$(id -u):$(id -g)" \
      -v "$PROJECT:$PROJECT" -v "$RUNNER_TEMP/budget-caddyfile:/tmp/budget-caddyfile:ro" -w "$PROJECT" \
      -e XDG_CONFIG_HOME=/tmp -e XDG_DATA_HOME=/tmp \
      --entrypoint /usr/local/bin/frankenphp infbyte-lab-frankenphp run --config /tmp/budget-caddyfile --adapter caddyfile >/dev/null ;;
  roadrunner)
    cat > "$RUNNER_TEMP/budget-rr.yaml" <<CONF
version: '3'
server:
  command: "php $PROJECT/lab/host-budget-peer.php roadrunner"
  relay: pipes
  env:
    RR_MODE: "http"
    RR_RELAY: "pipes"
http:
  address: 127.0.0.1:18781
  pool:
    num_workers: 2
    max_jobs: 0
logs:
  level: error
CONF
    setsid "$RUNNER_TEMP/lab-rr" serve -c "$RUNNER_TEMP/budget-rr.yaml" > "$EVIDENCE/host-budget.log" 2>&1 &
    host=$! ;;
  swoole|openswoole)
    setsid php lab/host-budget-peer.php "$HOST_MODE" > "$EVIDENCE/host-budget.log" 2>&1 &
    host=$! ;;
  *) echo 'Unexpected host' >&2; exit 1 ;;
esac
python3 "$GITHUB_WORKSPACE/qa/workloads/host-budget-client.py" "${SCENARIO#ecosystem-host-budget-}" | tee "$EVIDENCE/host-budget-result.json"
cp storage/lab-host-budget/*.jsonl "$EVIDENCE/"
