#!/usr/bin/env bash
set -Eeuo pipefail
cd "$PROJECT"
trap 'docker rm -f lab-broker >/dev/null 2>&1 || :' EXIT
case "$SCENARIO" in
  ecosystem-amqp-crash|ecosystem-amqp|ecosystem-amqp-dlq|ecosystem-amqp-ack-uncertain|ecosystem-amqp-wire-ack|ecosystem-amqp-consumer-failures)
    docker run -d --name lab-broker -p 127.0.0.1:15672:5672 -e RABBITMQ_DEFAULT_USER=experiment -e RABBITMQ_DEFAULT_PASS=fixture-only-password rabbitmq:4
    composer require php-amqplib/php-amqplib --no-interaction --prefer-dist --no-progress
    for i in $(seq 1 60); do
      if docker exec lab-broker rabbitmq-diagnostics -q ping; then break; fi
      sleep 1
    done
    docker exec lab-broker rabbitmq-diagnostics -q ping
    ;;
  ecosystem-sqs-fifo-groups|ecosystem-sqs-visibility-recovery|ecosystem-sqs|ecosystem-sqs-fifo|ecosystem-sqs-redrive|ecosystem-sqs-ack-uncertain|ecosystem-sqs-wire-ack|ecosystem-sqs-consumer-failures)
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
if [[ "$SCENARIO" == ecosystem-amqp-crash ]]; then
  export LAB_BROKER_CRASH_ROOT="$(mktemp -d "$RUNNER_TEMP/broker-crash.XXXXXXXX")"
  probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
  timeout 90s php "$probe" amqp-crash-seed > "$EVIDENCE/broker-crash-seed.log" 2>&1 &
  seed=$!
  trap 'kill "$seed" 2>/dev/null || :; wait "$seed" 2>/dev/null || :; docker rm -f lab-broker >/dev/null 2>&1 || :; rm -rf "$LAB_BROKER_CRASH_ROOT"' EXIT
  for attempt in {1..300}; do
    [[ -f "$LAB_BROKER_CRASH_ROOT/seeded" ]] && break
    kill -0 "$seed"
    sleep 0.1
  done
  test -f "$LAB_BROKER_CRASH_ROOT/seeded"
  docker kill lab-broker >/dev/null
  if timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/15672' 2>/dev/null; then exit 1; fi
  touch "$LAB_BROKER_CRASH_ROOT/stopped"
  wait "$seed"
  test -f "$LAB_BROKER_CRASH_ROOT/observed"
  docker start lab-broker >/dev/null
  for attempt in {1..60}; do
    if docker exec lab-broker rabbitmq-diagnostics -q check_port_connectivity; then break; fi
    sleep 2
  done
  docker exec lab-broker rabbitmq-diagnostics -q check_port_connectivity
  timeout 90s php "$probe" amqp-crash-recover | tee "$EVIDENCE/broker-crash-recovery.log"
elif [[ "$SCENARIO" == ecosystem-amqp-wire-ack ]]; then
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
elif [[ "$SCENARIO" == ecosystem-sqs-wire-ack ]]; then
  export LAB_SQS_FAULT_ROOT="$(mktemp -d "$RUNNER_TEMP/sqs-wire.XXXXXXXX")"
  python3 "$GITHUB_WORKSPACE/qa/workloads/sqs-ack-peer.py" "$LAB_SQS_FAULT_ROOT" > "$EVIDENCE/sqs-wire.log" 2>&1 &
  peer=$!
  trap 'kill "$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; rm -rf "$LAB_SQS_FAULT_ROOT"; docker rm -f lab-broker >/dev/null 2>&1 || :' EXIT
  for attempt in {1..100}; do
    [[ -f "$LAB_SQS_FAULT_ROOT/ready" ]] && break
    kill -0 "$peer"
    sleep 0.1
  done
  test -f "$LAB_SQS_FAULT_ROOT/ready"
  timeout 90s php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" sqs-wire-ack | tee "$EVIDENCE/ecosystem.log"
  cat "$LAB_SQS_FAULT_ROOT/before" "$LAB_SQS_FAULT_ROOT/after" > "$EVIDENCE/sqs-wire-faults.txt"
else
  php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log"
fi
