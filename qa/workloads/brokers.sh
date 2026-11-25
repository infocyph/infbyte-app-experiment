#!/usr/bin/env bash
set -Eeuo pipefail
cd "$PROJECT"
trap 'docker rm -f lab-broker >/dev/null 2>&1 || :' EXIT
case "$SCENARIO" in
  ecosystem-amqp|ecosystem-amqp-dlq|ecosystem-amqp-ack-uncertain)
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
php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log"
