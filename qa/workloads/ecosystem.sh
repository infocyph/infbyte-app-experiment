#!/usr/bin/env bash
set -euo pipefail
case "$SCENARIO" in
  ecosystem-auth-lifecycle|ecosystem-jwt-replay) bash "$GITHUB_WORKSPACE/qa/workloads/auth-lifecycle.sh" ;;
  ecosystem-remote-jwks) bash "$GITHUB_WORKSPACE/qa/workloads/jwks.sh" ;;
  ecosystem-di-preload)
    probe="$GITHUB_WORKSPACE/qa/workloads/di-preload.php"
    php "$probe" generate
    php -d opcache.enable_cli=1 -d "opcache.preload=$EVIDENCE/di-preload.php" -d "opcache.preload_user=$(id -un)" "$probe" verify | tee "$EVIDENCE/preload.log" ;;
  ecosystem-api-inventory) php "$GITHUB_WORKSPACE/qa/workloads/coverage-audit.php" | tee "$EVIDENCE/inventory.log" ;;
  ecosystem-risk-edge) bash "$GITHUB_WORKSPACE/qa/workloads/risk-edge.sh" ;;
  ecosystem-release-cutover) bash "$GITHUB_WORKSPACE/qa/workloads/release-cutover.sh" ;;
  ecosystem-tenants) bash "$GITHUB_WORKSPACE/qa/workloads/tenants.sh" ;;
  ecosystem-smtp-uncertain|ecosystem-smtp-tls-auth) bash "$GITHUB_WORKSPACE/qa/workloads/smtp-faults.sh" ;;
  ecosystem-mailbox-tls|ecosystem-mailbox-idle) bash "$GITHUB_WORKSPACE/qa/workloads/mailbox-tls.sh" ;;
  ecosystem-cache-tls|ecosystem-valkey-tls|ecosystem-counter-restart|ecosystem-valkey-counter-restart) bash "$GITHUB_WORKSPACE/qa/workloads/cache-tls.sh" ;;
  ecosystem-counter-uncertain|ecosystem-valkey-counter-uncertain) bash "$GITHUB_WORKSPACE/qa/workloads/counter-uncertain.sh" ;;
  ecosystem-counter-election|ecosystem-valkey-counter-election) bash "$GITHUB_WORKSPACE/qa/workloads/counter-election.sh" ;;
  ecosystem-cache-outage|ecosystem-di-cache-outage) bash "$GITHUB_WORKSPACE/qa/workloads/cache-outage.sh" ;;
  ecosystem-otp-durable) bash "$GITHUB_WORKSPACE/qa/workloads/otp-durable.sh" ;;
  ecosystem-supervisor|ecosystem-http-framing|ecosystem-http-backpressure) bash "$GITHUB_WORKSPACE/qa/workloads/runtime-boundaries.sh" ;;
  ecosystem-scylla|ecosystem-redis-failover) bash "$GITHUB_WORKSPACE/qa/workloads/cache-ha.sh" ;;
  ecosystem-wire-outbound-h3) bash "$GITHUB_WORKSPACE/qa/workloads/outbound-h3.sh" ;;
  ecosystem-wire-*) bash "$GITHUB_WORKSPACE/qa/workloads/wire.sh" ;;
  ecosystem-module-*) bash "$GITHUB_WORKSPACE/qa/workloads/module-matrix.sh" ;;
  ecosystem-amqp-tls) bash "$GITHUB_WORKSPACE/qa/workloads/broker-tls.sh" ;;
  ecosystem-amqp|ecosystem-sqs|ecosystem-amqp-dlq|ecosystem-sqs-fifo|ecosystem-sqs-redrive|ecosystem-amqp-ack-uncertain|ecosystem-sqs-ack-uncertain) bash "$GITHUB_WORKSPACE/qa/workloads/brokers.sh" ;;
  ecosystem-imap|ecosystem-pop3) bash "$GITHUB_WORKSPACE/qa/workloads/mailbox.sh" ;;
  ecosystem-redis-cluster|ecosystem-mongodb|ecosystem-s3|ecosystem-sftp|ecosystem-sftp-trust|ecosystem-sftp-outage|ecosystem-s3-outage|ecosystem-s3-publication-fault|ecosystem-ftp|ecosystem-webdav|ecosystem-gridfs|ecosystem-sftp2|ecosystem-async-s3|ecosystem-azure|ecosystem-gcs|ecosystem-storage-local-modes) bash "$GITHUB_WORKSPACE/qa/workloads/remote-services.sh" ;;
  ecosystem-http-policy|ecosystem-http-pool|ecosystem-http-transfer) bash "$GITHUB_WORKSPACE/qa/workloads/http-policy.sh" ;;
  ecosystem-endurance) bash "$GITHUB_WORKSPACE/qa/workloads/endurance.sh" ;;
  ecosystem-telemetry-otel)
    cd "$PROJECT"
    composer require open-telemetry/sdk --no-interaction --prefer-dist --no-progress
    composer check-platform-reqs --no-dev
    composer show --format=json > "$EVIDENCE/packages.json"
    php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" telemetry-otel | tee "$EVIDENCE/ecosystem.log" ;;
  ecosystem-closure)
    cd "$PROJECT"
    composer require opis/closure:^4.5 --no-interaction --prefer-dist --no-progress
    composer check-platform-reqs --no-dev
    composer show --format=json > "$EVIDENCE/packages.json"
    php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" closure | tee "$EVIDENCE/ecosystem.log" ;;
  ecosystem-remote-order-recovery) bash "$GITHUB_WORKSPACE/qa/workloads/remote-order-recovery.sh" ;;
  ecosystem-connected|ecosystem-recovery|ecosystem-active-recovery) bash "$GITHUB_WORKSPACE/qa/workloads/connected.sh" ;;
  ecosystem-core-lifecycle) bash "$GITHUB_WORKSPACE/qa/workloads/core-lifecycle.sh" ;;
  ecosystem-*) php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log" ;;
  *) echo "Unknown ecosystem scenario" >&2; exit 1 ;;
esac
