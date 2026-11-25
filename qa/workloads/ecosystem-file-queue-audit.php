<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\AuditException;
use Infocyph\Pathwise\Exceptions\QueueException;
use Infocyph\Pathwise\Observability\AuditTrail;
use Infocyph\Pathwise\Observability\CallbackAuditSink;
use Infocyph\Pathwise\Observability\PartitionedAuditSink;
use Infocyph\Pathwise\Queue\FileJobQueue;
use Infocyph\Pathwise\Queue\QueueReservation;

function fileQueueAuditWorkload(): void
{
    $root = evidenceDirectory() . '/file-queue';
    verify(mkdir($root, 0700), 'Queue fixture directory unavailable.');
    $path = $root . '/queue.json';
    $auditPath = $root . '/audit/events.jsonl';
    $queue = new FileJobQueue($path, reservationTimeout: 60, maxJobs: 12);
    $ids = [];
    for ($i = 0; $i < 12; ++$i) {
        $ids[] = $queue->enqueue('receipt', ['sequence' => $i], $i);
    }
    expectFailure(static fn() => $queue->enqueue('overflow'), QueueException::class, 'Queue count limit exceeded.');
    $results = parallelRace(static function () use ($path, $auditPath): int {
        $worker = new FileJobQueue($path, reservationTimeout: 60, maxJobs: 12);
        $lease = $worker->reserve();
        verify($lease instanceof QueueReservation, 'Worker lost a pending job.');
        new AuditTrail($auditPath)->log('receipt', ['id' => $lease->id, 'sequence' => $lease->payload['sequence']]);
        $worker->acknowledge($lease);
        expectFailure(static fn() => $worker->acknowledge($lease), QueueException::class, 'Settled lease replay accepted.');
        return 1;
    }, 'file-queue-audit', 12);
    verify(array_sum($results) === 12 && $queue->stats()['pending'] === 0 && $queue->stats()['processing'] === 0 && $queue->stats()['failed'] === 0, 'Queue settlement did not reconcile.');
    $records = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($auditPath, FILE_IGNORE_NEW_LINES));
    $actual = array_column(array_column($records, 'context'), 'id');
    sort($actual);
    sort($ids);
    verify($actual === $ids && count($records) === 12 && array_unique(array_column($records, 'operation')) === ['receipt'], 'Concurrent audit lost or duplicated job identities.');
    clearstatcache(true, $auditPath);
    verify((fileperms($auditPath) & 0777) === 0600, 'Audit file permissions not private.');

    $leasePath = $root . '/leases.json';
    $leaseQueue = new FileJobQueue($leasePath, reservationTimeout: 2, maxJobs: 4, maxPayloadBytes: 128);
    $low = $leaseQueue->enqueue('low', priority: 1);
    $high = $leaseQueue->enqueue('high', priority: 10);
    $initial = $leaseQueue->reserve();
    verify($initial instanceof QueueReservation && $initial->id === $high, 'Queue priority mismatch.');
    $leaseQueue->release($initial);
    $current = $leaseQueue->reserve();
    verify($current instanceof QueueReservation && $current->id === $high && $current->leaseToken !== $initial->leaseToken, 'Release did not generate independent ownership.');
    expectFailure(static fn() => $leaseQueue->acknowledge($initial), QueueException::class, 'Old lease acknowledged replacement.');
    $renewed = $leaseQueue->renew($current);
    verify($renewed->id === $high && $renewed->leaseToken === $current->leaseToken && $renewed->expiresAt >= $current->expiresAt, 'Renewal changed ownership.');
    $deadline = hrtime(true) + 5_000_000_000;
    while (time() < $renewed->expiresAt) {
        verify(hrtime(true) < $deadline, 'Lease expiry did not advance.');
        usleep(20_000);
    }
    expectFailure(static fn() => $leaseQueue->renew($renewed), QueueException::class, 'Expired lease renewed.');
    $replacement = new FileJobQueue($leasePath, reservationTimeout: 2, maxJobs: 4, maxPayloadBytes: 128);
    $claimed = $replacement->reserve();
    verify($claimed instanceof QueueReservation && $claimed->id === $high && $claimed->leaseToken !== $renewed->leaseToken, 'Expired job not reclaimed with new token.');
    expectFailure(static fn() => $leaseQueue->release($renewed), QueueException::class, 'Old owner released replacement reservation.');
    $replacement->acknowledge($claimed);
    $result = $replacement->process(static function (QueueReservation $reservation) use ($low): never {
        verify($reservation->id === $low, 'Unexpected failed job identity.');
        throw new DomainException('Fixture processing failure.');
    }, 1);
    verify($result->processed === 0 && $result->failed === 1 && $replacement->stats()['failed'] === 1, 'Failed job did not enter failure state.');
    expectFailure(static fn() => $replacement->enqueue('large', ['payload' => str_repeat('a', 256)]), QueueException::class, 'Payload limit bypassed.');
    $corrupt = $root . '/corrupt.json';
    verify(file_put_contents($corrupt, '{"version":1}') !== false, 'Malformed queue fixture failed.');
    expectFailure(static fn() => new FileJobQueue($corrupt), QueueException::class, 'Malformed queue state accepted.');
    verify(file_get_contents($corrupt) === '{"version":1}', 'Corrupt queue silently overwritten.');

    $partition = $root . '/partitioned';
    $partitioned = new AuditTrail(new PartitionedAuditSink($partition));
    for ($i = 0; $i < 3; ++$i) {
        $partitioned->log('partitioned', ['id' => $i]);
    }
    $entries = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($partition, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $entries[] = json_decode((string) file_get_contents($file->getPathname()), true, flags: JSON_THROW_ON_ERROR)['context']['id'];
        }
    }
    sort($entries);
    verify($entries === [0, 1, 2] && $partitioned->getLogFilePath() === null, 'Partitioned audit identity mismatch.');
    $captured = [];
    $callback = new AuditTrail(new CallbackAuditSink(static function (array $record) use (&$captured): void { $captured[] = $record; }));
    $callback->log('callback', ['value' => false]);
    verify(count($captured) === 1 && $captured[0]['context'] === ['value' => false], 'Callback audit lost falsey data.');
    $resource = fopen('php://memory', 'rb');
    verify(is_resource($resource), 'Invalid audit fixture resource unavailable.');
    try {
        expectFailure(static fn() => new AuditTrail($auditPath)->log('invalid', ['bad' => $resource]), AuditException::class, 'Invalid audit payload accepted.');
    } finally {
        fclose($resource);
    }
    verify(count(file($auditPath)) === 12, 'Rejected audit wrote partial line.');
    echo "12-process file queue settlement, audit integrity, stale lease fencing, bounds and sink contracts passed\n";
}
