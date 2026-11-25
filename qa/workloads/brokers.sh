#!/usr/bin/env bash
set -Eeuo pipefail
cd "$PROJECT"
trap 'docker rm -f lab-broker >/dev/null 2>&1 || :' EXIT
case "$SCENARIO" in
  ecosystem-amqp|ecosystem-amqp-dlq|ecosystem-amqp-ack-uncertain|ecosystem-amqp-wire-ack)
    docker run -d --name lab-broker -p 127.0.0.1:15672:5672 -e RABBITMQ_DEFAULT_USER=experiment -e RABBITMQ_DEFAULT_PASS=fixture-only-password rabbitmq:4
    composer require php-amqplib/php-amqplib --no-interaction --prefer-dist --no-progress
    for i in $(seq 1 60); do
      if docker exec lab-broker rabbitmq-diagnostics -q ping; then break; fi
      sleep 1
    done
    docker exec lab-broker rabbitmq-diagnostics -q ping
    ;;
  ecosystem-sqs|ecosystem-sqs-fifo|ecosystem-sqs-redrive|ecosystem-sqs-ack-uncertain)
    docker run -d --name lab-broker -p 127.0.0.1:19324:9324 softwaremill/elasticmq-native:latest
    composer require aws/aws-sdk-php --no-interaction --prefer-dist --no-progress
    for i in $(seq 1 60); do
      if curl -fsS --max-time 2 'http://127.0.0.1:19324/?Action=ListQueues' >/dev/null; then break; fi
      sleep 1
    done
    ;;
esac
composer check-platform-reqs --no-dev
composer show --format=json > "$EVIDENCE/packages.json"
if [[ "$SCENARIO" == ecosystem-amqp-wire-ack ]]; then
  export LAB_AMQP_FAULT_ROOT="$(mktemp -d "$RUNNER_TEMP/amqp-wire.XXXXXXXX")"
  python3 "$GITHUB_WORKSPACE/qa/workloads/amqp-ack-peer.py" "$LAB_AMQP_FAULT_ROOT" > "$EVIDENCE/amqp-wire.log" 2>&1 &
  peer=$!
  trap 'kill "$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; rm -rf "$LAB_AMQP_FAULT_ROOT"; docker rm -f lab-broker >/dev/null 2>&1 || :' EXIT
  for attempt in {1..100}; do
    [[ -f "$LAB_AMQP_FAULT_ROOT/ready" ]] && break
    kill -0 "$peer"
    sleep 0.1
  done
  test -f "$LAB_AMQP_FAULT_ROOT/ready"
  timeout 90s php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" amqp-wire-ack | tee "$EVIDENCE/ecosystem.log"
  cat "$LAB_AMQP_FAULT_ROOT/before" "$LAB_AMQP_FAULT_ROOT/after" > "$EVIDENCE/amqp-wire-faults.txt"
else
  php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log"
fi
