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
require __DIR__ . '/ecosystem-remote.php';
require __DIR__ . '/ecosystem-http.php';
require __DIR__ . '/ecosystem-contracts.php';
require __DIR__ . '/ecosystem-webrick.php';
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

$start = hrtime(true);
match ($argv[1] ?? '') {
    'sftp-trust' => sftpTrustWorkload(),
    'file-transactions' => fileTransactionsWorkload(),
    'mailbox-tls' => mailboxTlsWorkload(),
    'mailbox-untrusted' => mailboxUntrustedWorkload(),
    'mailbox-idle' => mailboxIdleWorkload(),
    'cache-outage' => cacheOutageWorkload(),
    'invalidation-processes' => invalidationProcessesWorkload(),
    'validation-rules' => validationRulesWorkload(),
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
    'coroutines' => coroutinesWorkload(),
    'async-files' => asyncFilesWorkload(),
    'pathwise-upload' => pathwiseUploadWorkload(),
    'pathwise-download' => pathwiseDownloadWorkload(),
    'mail-mime' => mailMimeWorkload(),
    'omnibus-workflow' => omnibusWorkflowWorkload(),
    'omnibus-redis' => omnibusRedisWorkload(),
    'intermix' => intermixWorkload(),
    'array-config' => arrayConfigWorkload(),
    'array-stream' => arrayStreamWorkload(),
    'uid-formats' => uidFormatsWorkload(),
    default => throw new InvalidArgumentException('Unknown ecosystem workload.'),
};
echo json_encode(['scenario' => $argv[1], 'milliseconds' => (hrtime(true) - $start) / 1e6, 'peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR), PHP_EOL;
