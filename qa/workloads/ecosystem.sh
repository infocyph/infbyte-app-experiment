#!/usr/bin/env bash
set -euo pipefail
bash "$GITHUB_WORKSPACE/qa/workloads/database-init.sh"
case "$SCENARIO" in
  ecosystem-auth-lifecycle|ecosystem-jwt-replay|ecosystem-consent-lifecycle|ecosystem-consent-permissions|ecosystem-consent-scope-policy) bash "$GITHUB_WORKSPACE/qa/workloads/auth-lifecycle.sh" ;;
  ecosystem-remote-jwks) bash "$GITHUB_WORKSPACE/qa/workloads/jwks.sh" ;;
  ecosystem-jwks-dns-*) bash "$GITHUB_WORKSPACE/qa/workloads/jwks-dns.sh" ;;
  ecosystem-di-preload)
    probe="$GITHUB_WORKSPACE/qa/workloads/di-preload.php"
    php "$probe" generate
    php -d opcache.enable_cli=1 -d "opcache.preload=$EVIDENCE/di-preload.php" -d "opcache.preload_user=$(id -un)" "$probe" verify | tee "$EVIDENCE/preload.log" ;;
  ecosystem-api-inventory) php "$GITHUB_WORKSPACE/qa/workloads/coverage-audit.php" | tee "$EVIDENCE/inventory.log" ;;
  ecosystem-risk-edge|ecosystem-risk-ja4) bash "$GITHUB_WORKSPACE/qa/workloads/risk-edge.sh" ;;
  ecosystem-risk-provider) bash "$GITHUB_WORKSPACE/qa/workloads/risk-provider.sh" ;;
  ecosystem-release-cutover) bash "$GITHUB_WORKSPACE/qa/workloads/release-cutover.sh" ;;
  ecosystem-tenants) bash "$GITHUB_WORKSPACE/qa/workloads/tenants.sh" ;;
  ecosystem-smtp-uncertain|ecosystem-smtp-tls-auth) bash "$GITHUB_WORKSPACE/qa/workloads/smtp-faults.sh" ;;
  ecosystem-mailbox-tls|ecosystem-mailbox-idle) bash "$GITHUB_WORKSPACE/qa/workloads/mailbox-tls.sh" ;;
  ecosystem-cache-tls|ecosystem-valkey-tls|ecosystem-counter-restart|ecosystem-valkey-counter-restart) bash "$GITHUB_WORKSPACE/qa/workloads/cache-tls.sh" ;;
  ecosystem-counter-uncertain|ecosystem-valkey-counter-uncertain) bash "$GITHUB_WORKSPACE/qa/workloads/counter-uncertain.sh" ;;
  ecosystem-counter-election|ecosystem-valkey-counter-election|ecosystem-otp-election-redis|ecosystem-otp-election-valkey) bash "$GITHUB_WORKSPACE/qa/workloads/counter-election.sh" ;;
  ecosystem-cache-outage|ecosystem-cache-valkey-outage|ecosystem-cache-memcached-outage|ecosystem-di-cache-outage|ecosystem-foundation-risk-outage-redis|ecosystem-foundation-risk-outage-valkey|ecosystem-foundation-risk-outage-memcached) bash "$GITHUB_WORKSPACE/qa/workloads/cache-outage.sh" ;;
  ecosystem-otp-durable) bash "$GITHUB_WORKSPACE/qa/workloads/otp-durable.sh" ;;
  ecosystem-supervisor|ecosystem-http-framing|ecosystem-http-backpressure|ecosystem-http-admission|ecosystem-http-connection-quotas) bash "$GITHUB_WORKSPACE/qa/workloads/runtime-boundaries.sh" ;;
  ecosystem-cache-scylla-outage-closed|ecosystem-cache-scylla-outage-open|ecosystem-scylla|ecosystem-cache-scylla-bulk|ecosystem-cache-scylla-tags|ecosystem-cache-scylla-remember|ecosystem-cache-scylla-metrics|ecosystem-cache-scylla-remote-capabilities|ecosystem-cache-scylla-payload-contracts|ecosystem-redis-failover) bash "$GITHUB_WORKSPACE/qa/workloads/cache-ha.sh" ;;
  ecosystem-wire-outbound-h3) bash "$GITHUB_WORKSPACE/qa/workloads/outbound-h3.sh" ;;
  ecosystem-wire-*) bash "$GITHUB_WORKSPACE/qa/workloads/wire.sh" ;;
  ecosystem-module-*) bash "$GITHUB_WORKSPACE/qa/workloads/module-matrix.sh" ;;
  ecosystem-amqp-tls) bash "$GITHUB_WORKSPACE/qa/workloads/broker-tls.sh" ;;
  ecosystem-amqp|ecosystem-sqs|ecosystem-amqp-dlq|ecosystem-sqs-fifo|ecosystem-sqs-redrive|ecosystem-amqp-ack-uncertain|ecosystem-amqp-wire-ack|ecosystem-sqs-ack-uncertain|ecosystem-sqs-wire-ack|ecosystem-amqp-consumer-failures|ecosystem-sqs-consumer-failures) bash "$GITHUB_WORKSPACE/qa/workloads/brokers.sh" ;;
  ecosystem-imap|ecosystem-pop3) bash "$GITHUB_WORKSPACE/qa/workloads/mailbox.sh" ;;
  ecosystem-sftp-rename-uncertain|ecosystem-sftp2-rename-before|ecosystem-sftp2-rename-after) bash "$GITHUB_WORKSPACE/qa/workloads/sftp-rename.sh" ;;
  ecosystem-webdav-http-publication-before|ecosystem-webdav-http-publication-after|ecosystem-async-s3-http-publication-before|ecosystem-async-s3-http-publication-after|ecosystem-azure-http-publication-before|ecosystem-azure-http-publication-after|ecosystem-gcs-http-publication-before|ecosystem-gcs-http-publication-after|ecosystem-redis-cluster|ecosystem-cache-mongodb-outage-closed|ecosystem-cache-mongodb-outage-open|ecosystem-mongodb|ecosystem-cache-redis-cluster-bulk|ecosystem-cache-redis-cluster-tags|ecosystem-cache-redis-cluster-remember|ecosystem-cache-redis-cluster-metrics|ecosystem-cache-redis-cluster-remote-capabilities|ecosystem-cache-redis-cluster-atomic|ecosystem-cache-redis-cluster-ttl-forms|ecosystem-cache-redis-cluster-mixed-atomic|ecosystem-cache-redis-cluster-payload-policy|ecosystem-cache-mongodb-bulk|ecosystem-cache-mongodb-tags|ecosystem-cache-mongodb-remember|ecosystem-cache-mongodb-metrics|ecosystem-cache-mongodb-remote-capabilities|ecosystem-cache-mongodb-atomic|ecosystem-cache-mongodb-ttl-forms|ecosystem-cache-mongodb-mixed-atomic|ecosystem-cache-mongodb-payload-policy|ecosystem-s3|ecosystem-sftp|ecosystem-sftp-trust|ecosystem-sftp-outage|ecosystem-sftp-publication-fault|ecosystem-sftp2-interrupted-upload|ecosystem-ftp-interrupted-upload|ecosystem-ftp-rename-before|ecosystem-ftp-rename-after|ecosystem-s3-outage|ecosystem-s3-publication-fault|ecosystem-ftp|ecosystem-webdav|ecosystem-gridfs|ecosystem-sftp2|ecosystem-async-s3|ecosystem-azure|ecosystem-gcs|ecosystem-storage-local-modes|ecosystem-ftp-outage|ecosystem-ftp-copy-contracts|ecosystem-ftp-staged-contracts|ecosystem-webdav-outage|ecosystem-webdav-copy-contracts|ecosystem-webdav-staged-contracts|ecosystem-gridfs-outage|ecosystem-gridfs-copy-contracts|ecosystem-gridfs-staged-contracts|ecosystem-sftp2-outage|ecosystem-sftp2-copy-contracts|ecosystem-sftp2-staged-contracts|ecosystem-async-s3-outage|ecosystem-async-s3-copy-contracts|ecosystem-async-s3-staged-contracts|ecosystem-azure-outage|ecosystem-azure-copy-contracts|ecosystem-azure-staged-contracts|ecosystem-gcs-outage|ecosystem-gcs-copy-contracts|ecosystem-gcs-staged-contracts) bash "$GITHUB_WORKSPACE/qa/workloads/remote-services.sh" ;;
  ecosystem-http-policy|ecosystem-http-pool|ecosystem-http-transfer|ecosystem-http-retry-safety|ecosystem-http-proxy) bash "$GITHUB_WORKSPACE/qa/workloads/http-policy.sh" ;;
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
