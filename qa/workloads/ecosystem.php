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
require __DIR__ . '/ecosystem-messaging.php';
require __DIR__ . '/ecosystem-messaging-events.php';
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
require __DIR__ . '/ecosystem-mailbox-tls.php';
require __DIR__ . '/ecosystem-storage-boundaries.php';
require __DIR__ . '/ecosystem-storage-outage.php';
require __DIR__ . '/ecosystem-utility-boundaries.php';
require __DIR__ . '/ecosystem-http-semantics.php';
require __DIR__ . '/ecosystem-file-lifecycle.php';
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
    'validation-sanitizers' => validationSanitizersWorkload(),
    'http-transfer' => httpTransferWorkload(),
    'http-retry-safety' => httpRetrySafetyWorkload(),
    'http-proxy' => httpProxyWorkload(),
    'amqp-dlq' => amqpDlqWorkload(),
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
    'di-contextual' => diContextualWorkload(),
    'di-factories' => diFactoriesWorkload(),
    'di-graph-failures' => diGraphFailuresWorkload(),
    'di-cache' => diCacheWorkload(),
    'di-cache-outage' => diCacheOutageWorkload(),
    'di-attributes' => diAttributesWorkload(),
    'file-index-retention' => fileIndexRetentionWorkload(),
    'archive-security' => archiveSecurityWorkload(),
    'archive-contracts' => archiveContractsWorkload(),
    'file-watch' => fileWatchWorkload(),
    'http-representations' => httpRepresentationsWorkload(),
    'http-signatures' => httpSignaturesWorkload(),
    'uid-families' => uidFamiliesWorkload(),
    'array-transforms' => arrayTransformsWorkload(),
    'array-set-contracts' => arraySetContractsWorkload(),
    'array-dto-guard' => arrayDtoGuardWorkload(),
    'array-helper-boundaries' => arrayHelperBoundariesWorkload(),
    'config-mutation-lifecycle' => configMutationLifecycleWorkload(),
    'di-environment' => diEnvironmentWorkload(),
    'sftp-trust' => sftpTrustWorkload(),
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
    'invalidation-processes' => invalidationProcessesWorkload(),
    'validation-rules' => validationRulesWorkload(),
    'validation-options' => validationOptionsWorkload(),
    'validation-nested-errors' => validationNestedErrorsWorkload(),
    'validation-locale' => validationLocaleWorkload(),
    'validation-database' => validationDatabaseWorkload(),
    'otp-backends' => otpBackendsWorkload(),
    'otp-drift' => otpDriftWorkload(),
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
