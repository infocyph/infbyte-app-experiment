<?php

declare(strict_types=1);

require getenv('PROJECT') . '/vendor/autoload.php';

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param class-string<Throwable> $type */
function expectFailure(callable $operation, string $type, string $message): void
{
    try {
        $operation();
    } catch (Throwable $failure) {
        if ($failure instanceof $type) {
            return;
        }

        throw $failure;
    }

    throw new RuntimeException($message);
}

function evidenceDirectory(): string
{
    $path = getenv('EVIDENCE');
    verify(is_string($path) && is_dir($path), 'Evidence directory unavailable.');

    return $path;
}

require __DIR__ . '/ecosystem-data.php';
require __DIR__ . '/ecosystem-security.php';
require __DIR__ . '/ecosystem-runtime.php';
require __DIR__ . '/ecosystem-runtime-budget.php';
require __DIR__ . '/ecosystem-messaging.php';
require __DIR__ . '/ecosystem-messaging-events.php';
require __DIR__ . '/ecosystem-messaging-ownership.php';
require __DIR__ . '/ecosystem-smtp-faults.php';
require __DIR__ . '/ecosystem-remote-order-recovery.php';
require __DIR__ . '/ecosystem-remote.php';
require __DIR__ . '/ecosystem-http.php';
require __DIR__ . '/ecosystem-contracts.php';
require __DIR__ . '/ecosystem-webrick.php';
require __DIR__ . '/ecosystem-telemetry.php';
require __DIR__ . '/ecosystem-jose.php';
require __DIR__ . '/ecosystem-mailbox.php';
require __DIR__ . '/ecosystem-brokers.php';
require __DIR__ . '/ecosystem-invalidation.php';
require __DIR__ . '/ecosystem-storage-adapters.php';
require __DIR__ . '/ecosystem-cache-ha.php';
require __DIR__ . '/ecosystem-otp-boundaries.php';
require __DIR__ . '/ecosystem-validation-matrix.php';
require __DIR__ . '/ecosystem-cache-faults.php';
require __DIR__ . '/ecosystem-cache-atomic.php';
require __DIR__ . '/ecosystem-cache-contracts.php';
require __DIR__ . '/ecosystem-cache-locking.php';
require __DIR__ . '/ecosystem-mailbox-tls.php';
require __DIR__ . '/ecosystem-storage-boundaries.php';
require __DIR__ . '/ecosystem-storage-outage.php';
require __DIR__ . '/ecosystem-utility-boundaries.php';
require __DIR__ . '/ecosystem-http-semantics.php';
require __DIR__ . '/ecosystem-file-lifecycle.php';
require __DIR__ . '/ecosystem-file-queue-audit.php';
require __DIR__ . '/ecosystem-utility-contracts.php';
require __DIR__ . '/ecosystem-utility-lifecycle.php';
require __DIR__ . '/ecosystem-value-crypto.php';
require __DIR__ . '/ecosystem-array-contracts.php';
require __DIR__ . '/ecosystem-di-factories.php';
require __DIR__ . '/ecosystem-di-runtime-contracts.php';
require __DIR__ . '/ecosystem-grpc-inbound.php';
require __DIR__ . '/ecosystem-validation-casts.php';
require __DIR__ . '/ecosystem-remote-jwks.php';
require __DIR__ . '/ecosystem-broker-boundaries.php';
require __DIR__ . '/ecosystem-broker-recovery.php';
require __DIR__ . '/ecosystem-http-transfer.php';
require __DIR__ . '/ecosystem-cache-tls.php';
require __DIR__ . '/ecosystem-route-boundaries.php';
require __DIR__ . '/ecosystem-counter-otp-contracts.php';

require __DIR__ . '/ecosystem-module-dependencies.php';

$start = hrtime(true);
match ($argv[1] ?? '') {
    'worker-budget' => workerBudgetWorkload(),
    'conditional-modules' => conditionalModulesWorkload(),
    'cache-tls' => cacheTlsWorkload(),
    'counter-tls-seed' => counterTlsWorkload('seed'),
    'counter-tls-offline' => counterTlsWorkload('offline'),
    'counter-tls-recover' => counterTlsWorkload('recover'),
    'counter-contracts' => counterContractsWorkload(),
    'counter-uncertain' => counterUncertainWorkload('redis', 6379),
    'valkey-counter-uncertain' => counterUncertainWorkload('valkey', 6380),
    'counter-election-seed' => counterElectionWorkload(true),
    'counter-election-verify' => counterElectionWorkload(false),
    'otp-valkey' => otpAdditionalBackendWorkload('valkey'),
    'otp-phpfiles' => otpAdditionalBackendWorkload('phpfiles'),
    'otp-file' => otpAdditionalBackendWorkload('file'),
    'otp-sqlite' => otpAdditionalBackendWorkload('sqlite'),
    'otp-redis' => otpAdditionalBackendWorkload('redis'),
    'otp-pdo' => otpAdditionalBackendWorkload('pdo'),
    'http-pool' => httpPoolWorkload(),
    'grpc-inbound' => grpcInboundWorkload(),
    'validation-casts' => validationCastsWorkload(),
    'validation-cast-composition' => validationCastCompositionWorkload(),
    'validation-sanitizers' => validationSanitizersWorkload(),
    'http-transfer' => httpTransferWorkload(),
    'http-retry-safety' => httpRetrySafetyWorkload(),
    'http-proxy' => httpProxyWorkload(),
    'amqp-dlq' => amqpDlqWorkload(),
    'amqp-consumer-failures' => brokerConsumerFailureWorkload('amqp'),
    'sqs-consumer-failures' => brokerConsumerFailureWorkload('sqs'),
    'amqp-ack-uncertain' => brokerAcknowledgementWorkload('amqp'),
    'amqp-wire-ack' => brokerAcknowledgementWorkload('amqp-wire'),
    'sqs-ack-uncertain' => brokerAcknowledgementWorkload('sqs'),
    'sqs-wire-ack' => brokerAcknowledgementWorkload('sqs-wire'),
    'amqp-tls-seed' => amqpTlsSeed(),
    'amqp-tls-recover' => amqpTlsRecover(),
    'sqs-redrive' => sqsRedriveWorkload(),
    'sqs-fifo' => sqsFifoWorkload(),
    'remote-jwks' => remoteJwksWorkload(),
    'uid-provider-boundaries' => uidProviderBoundariesWorkload(),
    'uid-filesystem' => uidFilesystemWorkload(),
    'uid-values' => uidValuesWorkload(),
    'randflake-conversions' => randflakeConversionsWorkload(),
    'detached-crypto' => detachedCryptoWorkload(),
    'crypto-key-lifecycle' => cryptoKeyLifecycleWorkload(),
    'config-reference' => configReferenceWorkload(),
    'fence-remix' => fenceRemixWorkload(),
    'remix-composition' => remixCompositionWorkload(),
    'di-contextual' => diContextualWorkload(),
    'di-factories' => diFactoriesWorkload(),
    'di-graph-failures' => diGraphFailuresWorkload(),
    'di-cache' => diCacheWorkload(),
    'di-cache-outage' => diCacheOutageWorkload(),
    'di-attributes' => diAttributesWorkload(),
    'file-index-retention' => fileIndexRetentionWorkload(),
    'archive-security' => archiveSecurityWorkload(),
    'archive-contracts' => archiveContractsWorkload(),
    'archive-late-rollback' => archiveLateRollbackWorkload(),
    'file-rollback-failure' => fileRollbackFailureWorkload(),
    'file-queue-audit' => fileQueueAuditWorkload(),
    'file-watch' => fileWatchWorkload(),
    'http-representations' => httpRepresentationsWorkload(),
    'http-signatures' => httpSignaturesWorkload(),
    'uid-families' => uidFamiliesWorkload(),
    'array-transforms' => arrayTransformsWorkload(),
    'array-set-contracts' => arraySetContractsWorkload(),
    'array-dto-guard' => arrayDtoGuardWorkload(),
    'array-helper-boundaries' => arrayHelperBoundariesWorkload(),
    'collection-ownership' => collectionOwnershipWorkload(),
    'config-mutation-lifecycle' => configMutationLifecycleWorkload(),
    'di-environment' => diEnvironmentWorkload(),
    'sftp-trust' => sftpTrustWorkload(),
    'sftp-rename-seed' => sftpRenameFaultWorkload('seed', (string) ($argv[2] ?? '')),
    'sftp-rename-fault' => sftpRenameFaultWorkload('fault', (string) ($argv[2] ?? '')),
    'sftp-rename-recover' => sftpRenameFaultWorkload('recover', (string) ($argv[2] ?? '')),
    'sftp-publication-seed' => sftpPublicationFaultWorkload('seed'),
    'sftp-publication-interrupt' => sftpPublicationFaultWorkload('interrupt'),
    'sftp-publication-recover' => sftpPublicationFaultWorkload('recover'),
    's3-publication-fault' => s3PublicationFaultWorkload(),
    'storage-outage-seed' => storageOutageWorkload('seed'),
    'storage-outage-denied' => storageOutageWorkload('denied'),
    'storage-outage-recover' => storageOutageWorkload('recover'),
    'file-transactions' => fileTransactionsWorkload(),
    'mailbox-tls' => mailboxTlsWorkload(),
    'mailbox-untrusted' => mailboxUntrustedWorkload(),
    'mailbox-idle' => mailboxIdleWorkload(),
    'cache-outage' => cacheOutageWorkload(),
    'cache-valkey-outage' => cacheOutageWorkload('valkey'),
    'cache-memcached-outage' => cacheOutageWorkload('memcached'),
    'cache-atomic-redis' => cacheAtomicWorkload('redis'),
    'cache-atomic-valkey' => cacheAtomicWorkload('valkey'),
    'cache-atomic-memcached' => cacheAtomicWorkload('memcached'),
    'cache-atomic-file' => cacheAtomicWorkload('file'),
    'cache-atomic-phpfiles' => cacheAtomicWorkload('phpfiles'),
    'cache-atomic-sqlite' => cacheAtomicWorkload('sqlite'),
    'cache-atomic-pdo' => cacheAtomicWorkload('pdo'),
    'cache-bulk-redis' => cacheBulkWorkload('redis'),
    'cache-bulk-valkey' => cacheBulkWorkload('valkey'),
    'cache-bulk-memcached' => cacheBulkWorkload('memcached'),
    'cache-bulk-file' => cacheBulkWorkload('file'),
    'cache-bulk-phpfiles' => cacheBulkWorkload('phpfiles'),
    'cache-bulk-sqlite' => cacheBulkWorkload('sqlite'),
    'cache-bulk-pdo' => cacheBulkWorkload('pdo'),
    'cache-tags-redis' => cacheTagsWorkload('redis'),
    'cache-tags-valkey' => cacheTagsWorkload('valkey'),
    'cache-tags-memcached' => cacheTagsWorkload('memcached'),
    'cache-tags-file' => cacheTagsWorkload('file'),
    'cache-tags-phpfiles' => cacheTagsWorkload('phpfiles'),
    'cache-tags-sqlite' => cacheTagsWorkload('sqlite'),
    'cache-tags-pdo' => cacheTagsWorkload('pdo'),
    'cache-remember-redis' => cacheRememberWorkload('redis'),
    'cache-remember-valkey' => cacheRememberWorkload('valkey'),
    'cache-remember-memcached' => cacheRememberWorkload('memcached'),
    'cache-remember-file' => cacheRememberWorkload('file'),
    'cache-remember-phpfiles' => cacheRememberWorkload('phpfiles'),
    'cache-remember-sqlite' => cacheRememberWorkload('sqlite'),
    'cache-remember-pdo' => cacheRememberWorkload('pdo'),
    'cache-ttl-forms-redis' => cacheTtlWorkload('redis'),
    'cache-ttl-forms-valkey' => cacheTtlWorkload('valkey'),
    'cache-ttl-forms-memcached' => cacheTtlWorkload('memcached'),
    'cache-ttl-forms-file' => cacheTtlWorkload('file'),
    'cache-ttl-forms-phpfiles' => cacheTtlWorkload('phpfiles'),
    'cache-ttl-forms-sqlite' => cacheTtlWorkload('sqlite'),
    'cache-ttl-forms-pdo' => cacheTtlWorkload('pdo'),
    'cache-mixed-atomic-redis' => cacheMixedAtomicWorkload('redis'),
    'cache-mixed-atomic-valkey' => cacheMixedAtomicWorkload('valkey'),
    'cache-mixed-atomic-memcached' => cacheMixedAtomicWorkload('memcached'),
    'cache-mixed-atomic-file' => cacheMixedAtomicWorkload('file'),
    'cache-mixed-atomic-phpfiles' => cacheMixedAtomicWorkload('phpfiles'),
    'cache-mixed-atomic-sqlite' => cacheMixedAtomicWorkload('sqlite'),
    'cache-mixed-atomic-pdo' => cacheMixedAtomicWorkload('pdo'),
    'cache-payload-policy-redis' => cachePayloadPolicyWorkload('redis'),
    'cache-payload-policy-valkey' => cachePayloadPolicyWorkload('valkey'),
    'cache-payload-policy-memcached' => cachePayloadPolicyWorkload('memcached'),
    'cache-payload-policy-file' => cachePayloadPolicyWorkload('file'),
    'cache-payload-policy-phpfiles' => cachePayloadPolicyWorkload('phpfiles'),
    'cache-payload-policy-sqlite' => cachePayloadPolicyWorkload('sqlite'),
    'cache-payload-policy-pdo' => cachePayloadPolicyWorkload('pdo'),
    'cache-lock-ownership-redis' => cacheLockOwnershipWorkload('redis'),
    'cache-lock-ownership-valkey' => cacheLockOwnershipWorkload('valkey'),
    'cache-lock-ownership-memcached' => cacheLockOwnershipWorkload('memcached'),
    'cache-lock-ownership-file' => cacheLockOwnershipWorkload('file'),
    'cache-lock-ownership-phpfiles' => cacheLockOwnershipWorkload('phpfiles'),
    'cache-lock-ownership-sqlite' => cacheLockOwnershipWorkload('sqlite'),
    'cache-lock-ownership-pdo' => cacheLockOwnershipWorkload('pdo'),
    'cache-stampede-redis' => cacheStampedeWorkload('redis'),
    'cache-stampede-valkey' => cacheStampedeWorkload('valkey'),
    'cache-stampede-memcached' => cacheStampedeWorkload('memcached'),
    'cache-stampede-file' => cacheStampedeWorkload('file'),
    'cache-stampede-phpfiles' => cacheStampedeWorkload('phpfiles'),
    'cache-stampede-sqlite' => cacheStampedeWorkload('sqlite'),
    'cache-stampede-pdo' => cacheStampedeWorkload('pdo'),
    'cache-metrics-redis' => cacheMetricsWorkload('redis'),
    'cache-metrics-valkey' => cacheMetricsWorkload('valkey'),
    'cache-metrics-memcached' => cacheMetricsWorkload('memcached'),
    'cache-metrics-file' => cacheMetricsWorkload('file'),
    'cache-metrics-phpfiles' => cacheMetricsWorkload('phpfiles'),
    'cache-metrics-sqlite' => cacheMetricsWorkload('sqlite'),
    'cache-metrics-pdo' => cacheMetricsWorkload('pdo'),
    'invalidation-processes' => invalidationProcessesWorkload(),
    'validation-rules' => validationRulesWorkload(),
    'validation-options' => validationOptionsWorkload(),
    'validation-nested-errors' => validationNestedErrorsWorkload(),
    'validation-locale' => validationLocaleWorkload(),
    'validation-database' => validationDatabaseWorkload(),
    'otp-backends' => otpBackendsWorkload(),
    'otp-drift' => otpDriftWorkload(),
    'otp-enrollment' => otpEnrollmentWorkload(),
    'scylla' => scyllaWorkload(),
    'redis-failover-seed' => redisFailoverWorkload(true),
    'redis-failover-verify' => redisFailoverWorkload(false),
    'storage-local-modes' => storageLocalModesWorkload(),
    'ftp' => ftpWorkload(),
    'webdav' => webdavWorkload(),
    'gridfs' => gridfsWorkload(),
    'sftp2' => sftp2Workload(),
    'async-s3' => asyncS3Workload(),
    'azure' => azureWorkload(),
    'gcs' => gcsWorkload(),
    'invalidation-sql' => invalidationSqlWorkload(),
    'invalidation-stream' => invalidationStreamWorkload(),
    'amqp' => amqpWorkload(),
    'sqs' => sqsWorkload(),
    'imap' => imapWorkload(),
    'pop3' => pop3Workload(),
    'jwt-jws' => jwtJwsWorkload(),
    'jwe' => jweWorkload(),
    'gateway' => gatewayWorkload(),
    'middleware-policies' => middlewarePolicyWorkload(),
    'vary-linter' => varyLinterWorkload(),
    'telemetry-risk' => telemetryRiskWorkload(),
    'telemetry-otel' => telemetryOtelWorkload(),
    'response-cache' => responseCacheWorkload(),
    'throttle' => throttleWorkload(),
    'cookie-protection' => cookieProtectionWorkload(),
    'uid-sequences' => uidSequencesWorkload(),
    'closure' => closureWorkload(),
    'config-layering' => configLayeringWorkload(),
    'pki' => pkiWorkload(),
    'crypto-algorithms' => cryptoAlgorithmsWorkload(),
    'validation-registry' => validationRegistryWorkload(),
    'http-policy' => httpPolicyWorkload(),
    'redis-cluster' => redisClusterWorkload(),
    'mongodb' => mongoWorkload(),
    's3' => objectStorageWorkload(),
    'sftp' => sftpWorkload(),
    'cache-tier' => cacheTierWorkload(),
    'cache-local' => cacheLocalWorkload(),
    'cache-integrity' => cacheIntegrityWorkload(),
    'cache-race' => cacheRaceWorkload(),
    'otp-race' => otpRaceWorkload(),
    'otp-recovery' => otpRecoveryWorkload(),
    'validation' => validationWorkload(),
    'crypto-file' => cryptoFileWorkload(),
    'crypto-envelope' => cryptoEnvelopeWorkload(),
    'routing' => routingWorkload(),
    'route-attributes' => routeAttributeWorkload(),
    'compression-codings' => compressionCodingsWorkload(),
    'coroutines' => coroutinesWorkload(),
    'async-files' => asyncFilesWorkload(),
    'pathwise-upload' => pathwiseUploadWorkload(),
    'pathwise-download' => pathwiseDownloadWorkload(),
    'mail-mime' => mailMimeWorkload(),
    'smtp-uncertain' => smtpUncertainWorkload(),
    'smtp-tls-auth' => smtpTlsAuthWorkload(),
    'omnibus-reservation-ownership' => omnibusReservationOwnership(),
    'omnibus-failure-claims' => omnibusFailureClaims(),
    'omnibus-workflow' => omnibusWorkflowWorkload(),
    'omnibus-middleware' => omnibusMiddlewareWorkload(),
    'omnibus-events' => omnibusEventsWorkload(),
    'remote-order-seed' => remoteOrderRecovery('seed'),
    'remote-order-offline' => remoteOrderRecovery('offline', (int) ($argv[2] ?? -1)),
    'remote-order-recover' => remoteOrderRecovery('recover'),
    'omnibus-redis' => omnibusRedisWorkload(),
    'intermix' => intermixWorkload(),
    'array-config' => arrayConfigWorkload(),
    'array-stream' => arrayStreamWorkload(),
    'uid-formats' => uidFormatsWorkload(),
    default => throw new InvalidArgumentException('Unknown ecosystem workload.'),
};
echo json_encode(['scenario' => $argv[1], 'milliseconds' => (hrtime(true) - $start) / 1e6, 'peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR), PHP_EOL;
