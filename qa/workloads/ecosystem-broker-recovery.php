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
    if ($provider === 'sqs') {
        $client = new SqsClient(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:19324',
            'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'http' => ['connect_timeout' => 3, 'timeout' => 10]]);
        $url = $client->createQueue(['QueueName' => 'ack-contract'])['QueueUrl'];
    }
    $connect = static function (?Closure $fault = null) use ($provider, $serializer, $client, $url, &$connection) {
        if ($provider === 'sqs') {
            return new SqsTransport(new LabSqsBackend($client, $url, ackFault: $fault), $serializer);
        }
        $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection('127.0.0.1', 15672, 'experiment', 'fixture-only-password');
        $channel = $connection->channel();
        $channel->queue_declare('ack-contract', false, true, false, false);
        return new AmqpTransport(new LabAmqpBackend($channel, $fault), $serializer);
    };
    $disconnect = static function () use (&$connection): void {
        if ($connection !== null) {
            $connection->close();
            $connection = null;
        }
    };
    try {
        foreach (['before', 'after'] as $phase) {
            $transport = $connect(static function (string $boundary) use ($phase): void {
                if ($phase === $boundary) {
                    throw new LabAcknowledgementUnknown('Injected acknowledgement boundary failure.');
                }
            });
            $transport->send(new Envelope(new LabJob($phase, 42)), 'ack-contract');
            $worker = $consumer($transport);
            expectFailure(static fn() => $worker->run('ack-contract', 1, 2.0), LabAcknowledgementUnknown::class, 'Consumer concealed unknown settlement.');
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
