#!/usr/bin/env bash
set -euo pipefail
cd "$PROJECT"
if [[ "$HOST_MODE" == roadrunner ]]; then
  cat > "$RUNNER_TEMP/lab-rr.yaml" <<CONF
version: '3'
server:
  command: "php $PROJECT/lab/host-worker.php roadrunner"
  relay: pipes
  env:
    RR_MODE: "http"
    RR_RELAY: "pipes"
http:
  address: 127.0.0.1:18781
  pool:
    num_workers: 4
    max_jobs: 0
logs:
  level: error
CONF
elif [[ "$HOST_MODE" == frankenphp ]]; then
  # The application's disposable public entry point selects the actual worker driver.
  cp "$PROJECT/lab/host-worker.php" "$PROJECT/public/index.php"
  cat > "$RUNNER_TEMP/lab-caddyfile" <<CONF
{
    auto_https off
    admin off
    frankenphp {
        worker $PROJECT/public/index.php 4
    }
}
http://127.0.0.1:18781 {
    root * $PROJECT/public
    php_server
}
CONF
else
  exit 1
fi
