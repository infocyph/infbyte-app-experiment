<?php

declare(strict_types=1);

use Aws\Sqs\SqsClient;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\AMQP\AmqpTransport;
use Infocyph\Omnibus\Integration\SQS\SqsTransport;
use PhpAmqpLib\Connection\AMQPSSLConnection;

function labAmqpTls(string $host, string $ca): AMQPSSLConnection
{
    return new AMQPSSLConnection($host, 15671, 'experiment', 'fixture-only-password', '/', [
        'cafile' => $ca,
        'verify_peer' => true,
        'verify_peer_name' => true,
    ], ['connection_timeout' => 3.0, 'read_write_timeout' => 5.0]);
}

function amqpTlsSeed(): void
{
    [, $serializer] = messagingSetup();
    $root = (string) getenv('LAB_AMQP_TLS_ROOT');
    $connection = labAmqpTls('localhost', $root . '/ca.crt');
    $channel = $connection->channel();
    try {
        $channel->queue_declare('tls-jobs', false, true, false, false);
        $transport = new AmqpTransport(new LabAmqpBackend($channel), $serializer);
        for ($i = 1; $i <= 20; ++$i) {
            $transport->send(new Envelope(new LabJob('tls-' . $i, $i)), 'tls-jobs');
        }
        $reserved = iterator_to_array($transport->receive('tls-jobs', 1));
        verify(count($reserved) === 1 && $reserved[0]->envelope()->message->id === 'tls-1', 'TLS reservation failed.');
        verify(file_put_contents($root . '/reserved-id', $reserved[0]->messageId, LOCK_EX) !== false, 'Reservation marker failed.');
    } finally {
        $connection->close();
    }
    foreach ([['localhost', $root . '/wrong.crt'], ['127.0.0.1', $root . '/ca.crt']] as [$host, $ca]) {
        $rejected = false;
        try {
            $invalid = labAmqpTls($host, $ca);
            $invalid->close();
        } catch (Exception) {
            $rejected = true;
        }
        verify($rejected, 'Wrong CA or broker identity accepted.');
    }
    echo "Verified TLS broker accepted durable messages and rejected invalid trust\n";
}

function amqpTlsRecover(): void
{
    [, $serializer] = messagingSetup();
    $root = (string) getenv('LAB_AMQP_TLS_ROOT');
    $connection = labAmqpTls('localhost', $root . '/ca.crt');
    $channel = $connection->channel();
    try {
        $transport = new AmqpTransport(new LabAmqpBackend($channel), $serializer);
        $seen = [];
        foreach ($transport->receive('tls-jobs', 20) as $delivery) {
            $id = $delivery->envelope()->message->id;
            verify(!isset($seen[$id]), 'Duplicate after broker restart.');
            if ($id === 'tls-1') {
                verify($delivery->messageId === file_get_contents($root . '/reserved-id') && $delivery->attempt === 2, 'Lost reservation identity/redelivery mismatch.');
            }
            $seen[$id] = true;
            $transport->acknowledge($delivery);
        }
        $expected = array_map(static fn(int $id): string => 'tls-' . $id, range(1, 20));
        verify(count($seen) === 20 && array_diff($expected, array_keys($seen)) === [] && $transport->size('tls-jobs') === 0, 'Durable queue reconciliation failed.');
        $transport->send(new Envelope(new LabJob('replacement', 21)), 'tls-jobs');
        $delivery = iterator_to_array($transport->receive('tls-jobs', 1))[0];
        verify($delivery->envelope()->message->id === 'replacement', 'Broker did not recover publication.');
        $transport->acknowledge($delivery);
    } finally {
        $connection->close();
    }
    echo "Actual broker stop/start, durable queue, unacknowledged delivery and replacement publication passed\n";
}

function sqsRedriveWorkload(): void
{
    [, $serializer] = messagingSetup();
    $client = new SqsClient(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:19324', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'http' => ['connect_timeout' => 3, 'timeout' => 10]]);
    $dead = $client->createQueue(['QueueName' => 'lab-dead'])['QueueUrl'];
    $arn = $client->getQueueAttributes(['QueueUrl' => $dead, 'AttributeNames' => ['QueueArn']])['Attributes']['QueueArn'];
    $source = $client->createQueue(['QueueName' => 'lab-redrive', 'Attributes' => [
        'RedrivePolicy' => json_encode(['deadLetterTargetArn' => $arn, 'maxReceiveCount' => '2'], JSON_THROW_ON_ERROR),
    ]])['QueueUrl'];
    $transport = new SqsTransport(new LabSqsBackend($client, $source), $serializer);
    $deadTransport = new SqsTransport(new LabSqsBackend($client, $dead), $serializer);
    try {
        $transport->send(new Envelope(new LabJob('poison', 1)), 'source');
        $receipts = [];
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $deadline = hrtime(true) + 15_000_000_000;
            do {
                $rows = iterator_to_array($transport->receive('source', 1, 2.0));
            } while ($rows === [] && hrtime(true) < $deadline);
            verify(count($rows) === 1, 'Expected poison delivery missing.');
            $delivery = $rows[0];
            verify($delivery->envelope()->message->id === 'poison' && $delivery->attempt === $attempt, 'Poison identity/attempt mismatch.');
            verify(!isset($receipts[$delivery->receipt]), 'Stale receipt reused.');
            $receipts[$delivery->receipt] = true;
            // Expire/release the receipt without acknowledging the message.
            $transport->release($delivery, 1.0);
        }
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            verify(iterator_to_array($transport->receive('source', 1, 2.0)) === [], 'Poison exceeded configured receive limit.');
            $rows = iterator_to_array($deadTransport->receive('dead', 1, 5.0));
        } while ($rows === [] && hrtime(true) < $deadline);
        verify(count($rows) === 1 && $rows[0]->envelope()->message->id === 'poison', 'Broker redrive did not preserve poison payload.');
        $deadTransport->acknowledge($rows[0]);
        $transport->send(new Envelope(new LabJob('healthy', 2)), 'source');
        $rows = iterator_to_array($transport->receive('source', 1, 5.0));
        verify(count($rows) === 1 && $rows[0]->envelope()->message->id === 'healthy', 'Healthy message did not proceed after redrive.');
        $transport->acknowledge($rows[0]);
        verify(iterator_to_array($deadTransport->receive('dead', 1)) === [], 'Duplicate dead-letter delivery.');
    } finally {
        $client->deleteQueue(['QueueUrl' => $source]);
        $client->deleteQueue(['QueueUrl' => $dead]);
    }
    echo "SQS-compatible real redrive policy, receipt generations, dead-letter consumption and healthy recovery passed\n";
}


final class LabAcknowledgementUnknown extends RuntimeException {}

function brokerAcknowledgementWorkload(string $provider): void
{
    $wire = in_array($provider, ['amqp-wire', 'sqs-wire'], true);
    $sqs = in_array($provider, ['sqs', 'sqs-wire'], true);
    $root = $wire ? (string) getenv($sqs ? 'LAB_SQS_FAULT_ROOT' : 'LAB_AMQP_FAULT_ROOT') : '';
    [$db, $serializer, , $clock] = messagingSetup();
    $db->statement('CREATE TABLE lab_job_attempts (id VARCHAR(100) PRIMARY KEY, attempts INTEGER NOT NULL)');
    $invoker = new \Infocyph\Omnibus\Handler\HandlerInvoker(new \Infocyph\Omnibus\Handler\HandlerMap([
        LabJob::class => static function (LabJob $job) use ($db): void {
            // Application-owned idempotent effect; no exactly-once broker claim.
            $db->transaction(static function ($connection) use ($job): void {
                $connection->table('lab_job_attempts')->upsert([['id' => $job->id, 'attempts' => 0]], ['id'], ['id']);
                $connection->update('UPDATE lab_job_attempts SET attempts = attempts + 1 WHERE id = ?', [$job->id]);
                if (!$connection->table('lab_job_effects')->where('id', '=', $job->id)->exists()) {
                    $connection->table('lab_job_effects')->insert(['id' => $job->id, 'step' => $job->step]);
                }
            });
        },
    ]));
    $failures = new \Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore($db, $serializer);
    $consumer = static fn($transport) => new \Infocyph\Omnibus\Consumer\Consumer(
        $transport, $invoker, new \Infocyph\Omnibus\Retry\ExponentialRetryStrategy(maximumAttempts: 1), $failures, $clock,
    );
    $connection = null;
    $client = null;
    $url = null;
    $faultClient = null;
    if ($sqs) {
        $client = new SqsClient(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:19324',
            'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'http' => ['connect_timeout' => 3, 'timeout' => 10]]);
        $url = $client->createQueue(['QueueName' => 'ack-contract'])['QueueUrl'];
        if ($wire) {
            $path = parse_url($url, PHP_URL_PATH);
            verify(is_string($path) && $path !== '', 'Fixture queue URL has no path.');
            $url = 'http://127.0.0.1:19324' . $path;
            $faultClient = new SqsClient([
                'version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:19324',
                'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'retries' => 0,
                'http' => [
                    'connect_timeout' => 3, 'timeout' => 10, 'expect' => false,
                    'curl' => [CURLOPT_CONNECT_TO => ['127.0.0.1:19324:127.0.0.1:19325']],
                ],
            ]);
        }
    }
    $connect = static function (?Closure $fault = null) use ($sqs, $serializer, $client, $faultClient, $url, &$connection, $wire) {
        if ($sqs) {
            $selected = $wire && $fault !== null ? $faultClient : $client;
            return new SqsTransport(new LabSqsBackend($selected, $url, ackFault: $fault), $serializer);
        }
        $port = $wire && $fault !== null ? 15673 : 15672;
        $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection('127.0.0.1', $port, 'experiment', 'fixture-only-password', connection_timeout: 3.0, read_write_timeout: 5.0);
        $channel = $connection->channel();
        $channel->queue_declare('ack-contract', false, true, false, false);
        return new AmqpTransport(new LabAmqpBackend($channel, $fault), $serializer);
    };
    $disconnect = static function () use (&$connection, $wire): void {
        if ($connection !== null) {
            try {
                $connection->close();
            } catch (\PhpAmqpLib\Exception\AMQPExceptionInterface $failure) {
                if (!$wire) {
                    throw $failure;
                }
            } finally {
                $connection = null;
            }
        }
    };
    try {
        foreach (['before', 'after'] as $phase) {
            if ($wire) {
                verify(file_put_contents($root . '/phase', $phase, LOCK_EX) === strlen($phase), 'Wire fault arm failed.');
            }
            $transport = $connect(static function (string $boundary) use ($phase, $wire): void {
                if (!$wire && $phase === $boundary) {
                    throw new LabAcknowledgementUnknown('Injected acknowledgement boundary failure.');
                }
            });
            $transport->send(new Envelope(new LabJob($phase, 42)), 'ack-contract');
            $worker = $consumer($transport);
            $failureType = match ($provider) {
                'amqp-wire' => \PhpAmqpLib\Exception\AMQPExceptionInterface::class,
                'sqs-wire' => \Aws\Exception\AwsException::class,
                default => LabAcknowledgementUnknown::class,
            };
            expectFailure(static fn() => $worker->run('ack-contract', 1, 2.0), $failureType, 'Consumer concealed unknown settlement.');
            if ($wire) {
                $expected = $sqs
                    ? ($phase === 'before' ? 'DeleteMessage withheld' : 'DeleteMessage 200 response withheld')
                    : ($phase === 'before' ? 'basic.ack withheld' : 'queue.declare-ok withheld after basic.ack');
                verify(is_file($root . '/' . $phase) && trim((string) file_get_contents($root . '/' . $phase)) === $expected, 'Expected wire settlement fault was not observed.');
                verify(!is_file($root . '/replayed-' . $phase), 'Ambiguous settlement was automatically replayed.');
            }
            verify($db->table('lab_job_effects')->where('id', '=', $phase)->count() === 1, 'Durable effect lost before settlement.');
            $disconnect();
            $replacement = $connect();
            $deadline = hrtime(true) + 12_000_000_000;
            if ($phase === 'before') {
                do {
                    $result = $consumer($replacement)->run('ack-contract', 1, 3.0);
                    if ($result->received === 0) { usleep(100000); }
                } while ($result->received === 0 && hrtime(true) < $deadline);
                verify($result->received === 1 && $result->succeeded === 1, 'Unsettled delivery was not recovered.');
            } else {
                // Wait past the SQS visibility timeout, rather than trusting a momentary empty queue.
                $until = hrtime(true) + 4_000_000_000;
                do {
                    verify($consumer($replacement)->run('ack-contract', 1, 2.0)->received === 0, 'Already settled delivery reappeared.');
                    usleep(100000);
                } while (hrtime(true) < $until);
            }
            $attempts = (int) $db->table('lab_job_attempts')->where('id', '=', $phase)->value('attempts');
            verify($attempts === ($phase === 'before' ? 2 : 1), 'Delivery execution count differs from actual settlement.');
            verify($db->table('lab_job_effects')->where('id', '=', $phase)->count() === 1, 'Duplicate business effect after ambiguous acknowledgement.');
            $disconnect();
        }
        verify($db->table('lab_job_effects')->count() === 2 && count($failures->all(100)) === 0, 'Settlement uncertainty misclassified as handler failure.');
    } finally {
        $disconnect();
        if ($client !== null && $url !== null) {
            $client->deleteQueue(['QueueUrl' => $url]);
        }
    }
    echo "$provider actual broker settlement, injected before/after acknowledgement ambiguity, replacement and durable idempotent effects passed\n";
}


final class LabPermanentBrokerFailure extends DomainException implements \Infocyph\Omnibus\Retry\NonRetryableFailure {}

function brokerConsumerFailureWorkload(string $provider): void
{
    [$db, $serializer, , $clock] = messagingSetup();
    $db->statement('CREATE TABLE lab_broker_attempts (id VARCHAR(100) PRIMARY KEY, attempts INTEGER NOT NULL)');
    $queue = 'consumer-contract';
    $connection = null;
    $client = null;
    $url = null;
    if ($provider === 'amqp') {
        $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection('127.0.0.1', 15672, 'experiment', 'fixture-only-password');
        $channel = $connection->channel();
        $channel->exchange_declare('lab-failure-exchange', 'direct', false, true, false);
        $channel->queue_declare('consumer-dead', false, true, false, false);
        $channel->queue_bind('consumer-dead', 'lab-failure-exchange', 'dead');
        $channel->queue_declare($queue, false, true, false, false, false, new \PhpAmqpLib\Wire\AMQPTable(['x-dead-letter-exchange' => 'lab-failure-exchange', 'x-dead-letter-routing-key' => 'dead']));
        $backend = new LabAmqpBackend($channel);
        $transport = new AmqpTransport($backend, $serializer);
    } else {
        $client = new SqsClient(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:19324', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'http' => ['connect_timeout' => 3, 'timeout' => 10]]);
        $url = $client->createQueue(['QueueName' => $queue])['QueueUrl'];
        $backend = new LabSqsBackend($client, $url);
        $transport = new SqsTransport($backend, $serializer);
    }
    try {
        $failures = new \Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore($db, $serializer);
        $handler = new \Infocyph\Omnibus\Handler\HandlerInvoker(new \Infocyph\Omnibus\Handler\HandlerMap([
            LabJob::class => static function (LabJob $job, \Infocyph\Omnibus\Envelope\Envelope $envelope) use ($db): void {
                $db->table('lab_broker_attempts')->upsert([['id' => $job->id, 'attempts' => 0]], ['id'], ['id']);
                $db->update('UPDATE lab_broker_attempts SET attempts = attempts + 1 WHERE id = ?', [$job->id]);
                $attempt = (int) $db->table('lab_broker_attempts')->where('id', '=', $job->id)->value('attempts');
                $stamp = $envelope->last(\Infocyph\Omnibus\Envelope\AttemptStamp::class);
                verify($stamp !== null && $stamp->attempt === $attempt, 'Broker attempt stamp changed.');
                if ($job->id === 'permanent') {
                    throw new LabPermanentBrokerFailure('permanent fixture');
                }
                if ($job->id === 'exhausted' || ($job->id === 'transient' && $attempt === 1)) {
                    throw new RuntimeException('retryable fixture');
                }
                $db->table('lab_job_effects')->insert(['id' => $job->id, 'step' => $job->step]);
            },
        ]));
        $retry = new \Infocyph\Omnibus\Retry\ExponentialRetryStrategy(maximumAttempts: 2, initialDelaySeconds: $provider === 'amqp' ? 0.0 : 3.0);
        $consumer = new \Infocyph\Omnibus\Consumer\Consumer($transport, $handler, $retry, $failures, $clock);
        $run = static function () use ($consumer, $queue) {
            $deadline = hrtime(true) + 15_000_000_000;
            do {
                $result = $consumer->run($queue, 1, 10.0);
                if ($result->received > 0) { return $result; }
                usleep(20000);
            } while (hrtime(true) < $deadline);
            throw new RuntimeException('Expected real broker delivery missing.');
        };
        foreach (['transient', 'exhausted', 'permanent', 'healthy'] as $id) {
            $transport->send(new Envelope(new LabJob($id, 42)), $queue);
            $first = $run();
            if ($id === 'transient' || $id === 'exhausted') {
                verify($first->released === 1 && $first->succeeded === 0 && $first->failed === 0, 'Retry did not release delivery.');
                if ($provider === 'sqs') {
                    verify($consumer->run($queue, 1, 10.0)->received === 0, 'Delayed retry became visible early.');
                }
                $second = $run();
                verify($second->succeeded === ($id === 'transient' ? 1 : 0) && $second->failed === ($id === 'exhausted' ? 1 : 0), 'Retry terminal outcome mismatch.');
            } else {
                verify($first->succeeded === ($id === 'healthy' ? 1 : 0) && $first->failed === ($id === 'permanent' ? 1 : 0) && $first->released === 0, 'Permanent/healthy terminal outcome mismatch.');
            }
        }
        $backend->send($queue, 'malformed-fixture', '{"not-an-envelope":true}', 0.0);
        $bad = $run();
        verify($bad->failed === 1 && $bad->released === 0, 'Undecodable delivery was retried or accepted.');
        $records = $failures->all(100);
        verify(count($records) === 3, 'Unexpected durable failure count.');
        $decoded = [];
        $raw = 0;
        foreach ($records as $record) {
            if ($record->envelope === null) {
                verify($record->payload === '{"not-an-envelope":true}' && $record->attempt === 1 && !$record->payloadTruncated, 'Raw failure evidence mismatch.');
                ++$raw;
            } else {
                $decoded[$record->envelope->message->id] = $record->attempt;
            }
        }
        ksort($decoded);
        verify($decoded === ['exhausted' => 2, 'permanent' => 1] && $raw === 1, 'Durable failure identity/attempt mismatch.');
        verify($db->table('lab_job_effects')->count() === 2 && $db->table('lab_job_effects')->where('id', '=', 'transient')->exists() && $db->table('lab_job_effects')->where('id', '=', 'healthy')->exists(), 'Business effects did not reconcile.');
        verify($consumer->run($queue, 1, 10.0)->received === 0, 'Settled delivery remained in source queue.');
        if ($provider === 'amqp') {
            $dead = iterator_to_array($transport->receive('consumer-dead', 3));
            verify(count($dead) === 3, 'RabbitMQ DLX did not route all terminal failures.');
            $ids = [];
            foreach ($dead as $delivery) {
                $ids[] = $delivery->decodingFailure() === null ? $delivery->envelope()->message->id : 'malformed';
                $transport->acknowledge($delivery);
            }
            sort($ids);
            verify($ids === ['exhausted', 'malformed', 'permanent'] && $transport->size('consumer-dead') === 0, 'DLX reconciliation failed.');
        }
        echo "Real broker consumer retry, permanent rejection, decode failure and durable effect reconciliation passed\n";
    } finally {
        if ($connection !== null) { $connection->close(); }
        if ($client !== null && $url !== null) { $client->deleteQueue(['QueueUrl' => $url]); }
    }
}

function brokerRecordEffect(\Infocyph\DBLayer\Connection\Connection $db, LabJob $job): void
{
    $db->transaction(static function ($connection) use ($job): void {
        $existing = $connection->table('lab_job_effects')->find($job->id);
        if ($existing === null) {
            verify($connection->table('lab_job_effects')->insert(['id' => $job->id, 'step' => $job->step]), 'Business effect publication failed.');
        } else {
            verify((int) $existing['step'] === $job->step, 'Redelivery changed committed effect.');
        }
    });
}

function amqpCrashContracts(bool $seed): void
{
    [$db, $serializer] = messagingSetup($seed);
    $root = (string) getenv('LAB_BROKER_CRASH_ROOT');
    $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection('127.0.0.1', 15672, 'experiment', 'fixture-only-password', connection_timeout: 3.0, read_write_timeout: 5.0);
    $channel = $connection->channel();
    $queue = 'crash-contract';
    $transport = new AmqpTransport(new LabAmqpBackend($channel), $serializer);
    if ($seed) {
        $channel->queue_declare($queue, false, true, false, false);
        $channel->confirm_select();
        $confirmed = 0;
        $channel->set_ack_handler(static function () use (&$confirmed): void { ++$confirmed; });
        $channel->set_nack_handler(static function (): never { throw new RuntimeException('Broker negatively acknowledged publication.'); });
        foreach (range(1, 12) as $id) {
            $transport->send(new Envelope(new LabJob('crash-' . $id, $id)), $queue);
        }
        $channel->wait_for_pending_acks_returns(10.0);
        verify($confirmed === 12, 'Durable publication confirmations incomplete.');
        $first = iterator_to_array($transport->receive($queue, 1))[0];
        verify($first->envelope()->message->id === 'crash-1', 'Initial delivery differs.');
        brokerRecordEffect($db, $first->envelope()->message);
        verify(file_put_contents($root . '/reserved-id', $first->messageId, LOCK_EX) !== false && touch($root . '/seeded'), 'Reservation marker failed.');
        cacheWaitMarker($root . '/stopped');
        expectFailure(static fn() => $transport->acknowledge($first), \PhpAmqpLib\Exception\AMQPExceptionInterface::class, 'Crashed broker acknowledged outstanding delivery.');
        verify(touch($root . '/observed'), 'Crash evidence marker unavailable.');
        // The killed connection cannot be reused; do not attempt to settle its receipt.
        return;
    }
    try {
        $seen = [];
        foreach ($transport->receive($queue, 12) as $delivery) {
            $job = $delivery->envelope()->message;
            verify(!isset($seen[$job->id]), 'Duplicate recovery delivery.');
            if ($job->id === 'crash-1') {
                verify($delivery->messageId === file_get_contents($root . '/reserved-id') && $delivery->attempt >= 2, 'Outstanding delivery lost its identity or redelivery marker.');
            }
            brokerRecordEffect($db, $job);
            $seen[$job->id] = $job->step;
            $transport->acknowledge($delivery);
        }
        $expected = [];
        foreach (range(1, 12) as $id) {
            $expected['crash-' . $id] = $id;
        }
        ksort($seen);
        ksort($expected);
        verify($seen === $expected && $transport->size($queue) === 0 && $db->table('lab_job_effects')->count() === 12, 'Crash recovery lost or duplicated durable effects.');
        foreach ($expected as $id => $step) {
            verify((int) $db->table('lab_job_effects')->find($id)['step'] === $step, 'Recovered SQL ledger differs.');
        }
    } finally {
        $connection->close();
    }
    echo "RabbitMQ crash, confirmed publication and independent SQL reconciliation completed\n";
}

function sqsOwnershipContracts(string $contract): void
{
    [$db, $serializer] = messagingSetup();
    $client = new SqsClient(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:19324',
        'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'http' => ['connect_timeout' => 3, 'timeout' => 10]]);
    $fifo = $contract === 'fifo-groups';
    $queue = $fifo ? 'ownership.fifo' : 'ownership';
    $url = $client->createQueue(['QueueName' => $queue] + ($fifo ? ['Attributes' => ['FifoQueue' => 'true', 'ContentBasedDeduplication' => 'false']] : []))['QueueUrl'];
    $transport = static fn(string $group) => new SqsTransport(new LabSqsBackend($client, $url, fifo: $fifo, group: $group), $serializer);
    $a = $transport('alpha');
    $b = $transport('beta');
    try {
        if ($fifo) {
            $a->send(new Envelope(new LabJob('alpha-1', 1)), $queue);
            $a->send(new Envelope(new LabJob('alpha-2', 2)), $queue);
            $first = iterator_to_array($a->receive($queue, 1, 30.0))[0];
            verify($first->envelope()->message->id === 'alpha-1', 'First group ordering differs.');
            $sent = $b->send(new Envelope(new LabJob('beta-1', 3)), $queue);
            $b->send($sent, $queue);
            $b->send(new Envelope(new LabJob('beta-2', 4)), $queue);
            foreach (['beta-1', 'beta-2'] as $id) {
                $delivery = iterator_to_array($b->receive($queue, 1, 10.0))[0];
                verify($delivery->envelope()->message->id === $id, 'Independent group blocked or deduplication failed.');
                brokerRecordEffect($db, $delivery->envelope()->message);
                $b->acknowledge($delivery);
            }
            verify(iterator_to_array($b->receive($queue, 1)) === [], 'Blocked group advanced or duplicate beta delivery appeared.');
            brokerRecordEffect($db, $first->envelope()->message);
            $a->acknowledge($first);
            $last = iterator_to_array($b->receive($queue, 1, 10.0))[0];
            verify($last->envelope()->message->id === 'alpha-2', 'First group did not resume.');
            brokerRecordEffect($db, $last->envelope()->message);
            $b->acknowledge($last);
            verify($db->table('lab_job_effects')->count() === 4 && iterator_to_array($a->receive($queue, 1)) === [], 'FIFO group effects not reconciled.');
        } else {
            $a->send(new Envelope(new LabJob('expired', 7)), $queue);
            $first = iterator_to_array($a->receive($queue, 1, 3.0))[0];
            brokerRecordEffect($db, $first->envelope()->message);
            verify(iterator_to_array($b->receive($queue, 1, 5.0)) === [], 'In-flight message available before expiry.');
            $deadline = hrtime(true) + 15_000_000_000;
            do {
                $rows = iterator_to_array($b->receive($queue, 1, 10.0));
            } while ($rows === [] && hrtime(true) < $deadline);
            verify(count($rows) === 1, 'Expired reservation not recovered.');
            $next = $rows[0];
            verify($next->messageId === $first->messageId && $next->receipt !== $first->receipt && $next->attempt >= 2, 'Replacement receipt/attempt differs.');
            brokerRecordEffect($db, $next->envelope()->message);
            $b->acknowledge($next);
            verify($db->table('lab_job_effects')->count() === 1 && (int) $db->table('lab_job_effects')->find('expired')['step'] === 7, 'Visibility recovery duplicated durable effect.');
            verify(iterator_to_array($a->receive($queue, 1)) === [], 'Settled replacement remained deliverable.');
        }
    } finally {
        $client->deleteQueue(['QueueUrl' => $url]);
    }
    echo "SQS-compatible $contract with independent SQL effect reconciliation completed\n";
}
