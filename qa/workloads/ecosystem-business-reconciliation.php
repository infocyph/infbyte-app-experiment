<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\TransactionException;
use Infocyph\Epicrypt\DataProtection\ProtectionAlgorithm;
use Infocyph\Epicrypt\DataProtection\ProtectionOptions;
use Infocyph\Epicrypt\DataProtection\StringProtector;
use Infocyph\Epicrypt\Exception\EpicryptException;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Failure\FailureManager;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Transport\InvalidReservation;
use Infocyph\Pathwise\FileManager\SafeFileWriter;

function businessCacheQueueContracts(): void
{
    [$db, $serializer] = messagingSetup();
    [$peer] = messagingSetup(false);
    $clock = new LabMessagingClock();
    $db->table('lab_job_effects')->insert(['id' => 'order', 'step' => 1]);
    $db->setQueryCache(Cache::redis('business-query-cache'));
    $peer->setQueryCache(Cache::redis('business-query-cache'));
    $read = static fn(Connection $c): int => (int) $c->table('lab_job_effects')->where('id', '=', 'order')->cacheFor(600)->value('step');
    $send = new DBLayerTransport($db, $serializer, $clock);
    $receive = new DBLayerTransport($peer, $serializer, $clock);
    verify($read($db) === 1 && $read($peer) === 1, 'Initial shared projection differs.');
    try {
        $db->transaction(static function (Connection $c) use ($send, $read): never {
            $c->table('lab_job_effects')->where('id', '=', 'order')->update(['step' => 2]);
            $send->send(new Envelope(new LabJob('order', 2)), 'orders');
            verify($read($c) === 2, 'Transaction observed stale cached projection.');
            throw new DomainException('rollback');
        });
        throw new LogicException('Rollback did not occur.');
    } catch (TransactionException $failure) {
        verify($failure->getPrevious() instanceof DomainException, 'Unexpected rollback cause.');
    }
    verify($read($peer) === 1 && $receive->size('orders') === 0, 'Rollback leaked projection or message.');
    $db->transaction(static function (Connection $c) use ($send): void {
        $c->table('lab_job_effects')->where('id', '=', 'order')->update(['step' => 3]);
        $send->send(new Envelope(new LabJob('order', 3)), 'orders');
    });
    $delivery = iterator_to_array($receive->receive('orders', 1))[0];
    verify($delivery->envelope()->message->step === 3 && $read($peer) === 3, 'Committed notification preceded current projection.');
    $receive->acknowledge($delivery);
    verify($receive->size('orders') === 0 && $peer->table('omnibus_messages')->count() === 0, 'Queue terminal state differs.');
    echo "SQL business/queue transaction and shared Redis query-cache reconciliation passed\n";
}

function businessReceiptRedeliveryContracts(): void
{
    [$db, $serializer] = messagingSetup();
    [$peer] = messagingSetup(false);
    $clock = new LabMessagingClock();
    $queue = new DBLayerTransport($db, $serializer, $clock);
    $replacement = new DBLayerTransport($peer, $serializer, $clock);
    $queue->send(new Envelope(new LabJob('receipt', 42), [new MessageIdStamp('receipt-id')]), 'orders');
    $cache = Cache::redis('business-receipt-digest');
    $path = evidenceDirectory() . '/protected-receipt';
    $algorithm = ProtectionAlgorithm::XCHACHA20_POLY1305;
    $protector = StringProtector::create($algorithm);
    $keys = new KeyRing([new KeyRingEntry('receipt', random_bytes(32), KeyStatus::ACTIVE, KeyPurpose::DATA_PROTECTION, $algorithm->value)]);
    $options = new ProtectionOptions('receipt/v1', 'tenant=alpha;order=receipt');
    $plain = json_encode(['order' => 'receipt', 'amount' => 42], JSON_THROW_ON_ERROR);
    $first = iterator_to_array($queue->receive('orders', 1, 5.0))[0];
    $encoded = $protector->protectWithKeyRing($plain, $keys, $options);
    $writer = new SafeFileWriter($path);
    $writer->enableAtomicWrite();
    verify($writer->writeBinary($encoded) === strlen($encoded), 'Protected document write failed.');
    $writer->close();
    verify($protector->unprotectWithKeyRing((string) file_get_contents($path), $keys, $options)->value === $plain, 'Persisted document digest differs.');
    $db->table('lab_job_effects')->insert(['id' => 'receipt', 'step' => 42]);
    verify($cache->set('receipt', hash('sha256', $plain), 600), 'Digest publication failed.');
    // Simulate lost worker after committed effect but before acknowledging delivery.
    $clock->advance(5);
    $second = iterator_to_array($replacement->receive('orders', 1, 5.0))[0];
    verify($second->messageId === $first->messageId && $second->attempt === 2 && $second->receipt !== $first->receipt, 'Expired delivery did not reconcile.');
    expectFailure(static fn() => $queue->acknowledge($first), InvalidReservation::class, 'Stale delivery settled replacement.');
    $job = $second->envelope()->message;
    verify((int) $peer->table('lab_job_effects')->where('id', '=', $job->id)->value('step') === $job->step, 'Durable effect unavailable to replacement.');
    $persisted = (string) file_get_contents($path);
    $decoded = $protector->unprotectWithKeyRing($persisted, $keys, $options)->value;
    verify($persisted === $encoded && $decoded === $plain && Cache::redis('business-receipt-digest')->get('receipt') === hash('sha256', $decoded), 'Replacement receipt/cache reconciliation failed.');
    expectFailure(static fn() => $protector->unprotectWithKeyRing($persisted, $keys, new ProtectionOptions('receipt/v1', 'tenant=beta;order=receipt')), EpicryptException::class, 'Foreign tenant decrypted receipt.');
    $replacement->acknowledge($second);
    verify($peer->table('lab_job_effects')->count() === 1 && $replacement->size('orders') === 0 && file_get_contents($path) === $encoded, 'Redelivery duplicated effect or changed document.');
    unlink($path);
    echo "SQL lease redelivery after committed effect, protected Pathwise receipt and shared digest reconciliation passed\n";
}

function businessPoisonQueueContracts(): void
{
    [$db, $serializer, $invoker] = messagingSetup();
    [$peer] = messagingSetup(false);
    $clock = new LabMessagingClock();
    $queue = new DBLayerTransport($db, $serializer, $clock);
    $queue->send(new Envelope(new LabJob('poison', 1), [new MessageIdStamp('poison-id')]), 'orders');
    $queue->send(new Envelope(new LabJob('valid', 2), [new MessageIdStamp('valid-id')]), 'orders');
    $raw = '{"version":999,"message":{"type":"unknown"}}';
    verify($db->table('omnibus_messages')->where('message_id', '=', 'poison-id')->update(['payload' => $raw]) === 1, 'Poison fixture failed.');
    $transport = new DBLayerTransport($peer, $serializer, $clock);
    $store = new DBLayerFailureStore($peer, $serializer, clock: $clock);
    $consumer = new Consumer($transport, $invoker, new ExponentialRetryStrategy(maximumAttempts: 1), $store, $clock);
    $result = $consumer->run('orders', 2);
    verify($result->received === 2 && $result->succeeded === 1 && $result->failed === 1 && $result->released === 0, 'Poison message blocked healthy delivery or counter conservation failed.');
    $failed = $store->find('poison-id');
    verify($failed !== null && $failed->payload === $raw && $failed->envelope === null && $failed->attempt === 1, 'Raw poison evidence was lost.');
    verify($db->table('lab_job_effects')->count() === 1 && (int) $db->table('lab_job_effects')->where('id', '=', 'valid')->value('step') === 2 && $db->table('omnibus_messages')->count() === 0, 'Healthy/poison effects not reconciled.');
    verify($consumer->run('orders', 2)->received === 0, 'Settled poison redelivered.');
    echo "Real SQL stored-payload corruption, durable dead letter and healthy delivery isolation passed\n";
}

function businessFailureRecoveryContracts(): void
{
    [$db, $serializer] = messagingSetup();
    [$peer] = messagingSetup(false);
    $clock = new LabMessagingClock();
    $queue = new DBLayerTransport($db, $serializer, $clock);
    $store = new DBLayerFailureStore($db, $serializer, clock: $clock);
    $queue->send(new Envelope(new LabJob('order', 42), [new MessageIdStamp('order-id')]), 'orders');
    $failing = new HandlerInvoker(new HandlerMap([LabJob::class => static function (LabJob $job) use ($db): void {
        $db->transaction(static function (Connection $c) use ($job): never {
            $c->table('lab_job_effects')->insert(['id' => $job->id, 'step' => $job->step]);
            throw new DomainException('temporary business failure');
        });
    }]));
    $consumer = new Consumer($queue, $failing, new ExponentialRetryStrategy(maximumAttempts: 1), $store, $clock);
    verify($consumer->run('orders', 1)->failed === 1 && $peer->table('lab_job_effects')->count() === 0, 'Failed handler leaked SQL effect.');
    $replacement = new DBLayerTransport($peer, $serializer, $clock);
    $failures = new DBLayerFailureStore($peer, $serializer, clock: $clock);
    verify($failures->find('order-id')?->envelope?->message->step === 42 && $replacement->size('orders') === 0, 'Failure evidence or terminal queue state differs.');
    $manager = new FailureManager($failures);
    $manager->retry('order-id', $replacement);
    $success = new HandlerInvoker(new HandlerMap([LabJob::class => static function (LabJob $job) use ($peer): void {
        $peer->transaction(static function (Connection $c) use ($job): void {
            $c->table('lab_job_effects')->insert(['id' => $job->id, 'step' => $job->step]);
        });
    }]));
    $recovered = new Consumer($replacement, $success, new ExponentialRetryStrategy(maximumAttempts: 1), $failures, $clock);
    verify($recovered->run('orders', 1)->succeeded === 1 && $recovered->run('orders', 1)->received === 0, 'Recovered consumer did not settle exactly once.');
    verify($db->table('lab_job_effects')->count() === 1 && (int) $db->table('lab_job_effects')->value('step') === 42 && $db->table('omnibus_messages')->count() === 0 && $db->table('omnibus_failures')->count() === 0, 'Replay did not reconcile business, failure and queue rows.');
    echo "Failed business transaction, durable dead letter and independent consumer replay passed\n";
}
