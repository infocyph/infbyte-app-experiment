<?php

declare(strict_types=1);

use Aws\Sqs\SqsClient;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\AMQP\AmqpTransport;
use Infocyph\Omnibus\Integration\SQS\SqsTransport;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;

function amqpDlqWorkload(): void
{
    [, $serializer] = messagingSetup();
    $connect = static fn(): AMQPStreamConnection => new AMQPStreamConnection('127.0.0.1', 15672, 'experiment', 'fixture-only-password');
    $connection = $connect();
    $channel = $connection->channel();
    try {
        $channel->exchange_declare('lab-dlx', 'direct', false, true, false);
        $channel->queue_declare('dead', false, true, false, false);
        $channel->queue_bind('dead', 'lab-dlx', 'dead');
        $channel->queue_declare('jobs', false, true, false, false, false, new AMQPTable(['x-dead-letter-exchange' => 'lab-dlx', 'x-dead-letter-routing-key' => 'dead']));
        $transport = new AmqpTransport(new LabAmqpBackend($channel), $serializer);
        $transport->send(new Envelope(new LabJob('reserved', 1)), 'jobs');
        $transport->send(new Envelope(new LabJob('poison', 2)), 'jobs');
        $first = iterator_to_array($transport->receive('jobs', 1))[0];
        verify($first->envelope()->message->id === 'reserved', 'Unexpected reservation.');
        // Actual connection closure leaves an unacknowledged delivery.
        $connection->close();
        $connection = $connect();
        $channel = $connection->channel();
        $transport = new AmqpTransport(new LabAmqpBackend($channel), $serializer);
        $deadline = hrtime(true) + 10_000_000_000;
        $seen = [];
        while (count($seen) < 2 && hrtime(true) < $deadline) {
            foreach ($transport->receive('jobs', 2) as $reservation) {
                $id = $reservation->envelope()->message->id;
                verify(!isset($seen[$id]), 'Duplicate after channel recovery.');
                $seen[$id] = true;
                if ($id === 'reserved') {
                    verify($reservation->messageId === $first->messageId && $reservation->attempt === 2, 'Lost reservation was not redelivered with identity.');
                    $transport->acknowledge($reservation);
                } elseif ($id === 'poison') {
                    $transport->reject($reservation);
                } else {
                    throw new RuntimeException('Unexpected queued message.');
                }
            }
            usleep(10000);
        }
        verify(count($seen) === 2 && $transport->size('jobs') === 0, 'Recovered queue reconciliation failed.');
        do {
            $dead = iterator_to_array($transport->receive('dead', 1));
            if ($dead === []) {
                usleep(20000);
            }
        } while ($dead === [] && hrtime(true) < $deadline);
        verify(count($dead) === 1 && $dead[0]->envelope()->message->id === 'poison', 'Actual dead-letter exchange did not route rejected message.');
        $transport->acknowledge($dead[0]);
        verify($transport->size('dead') === 0, 'Dead-letter acknowledgement failed.');
    } finally {
        if ($channel->is_open()) {
            $channel->close();
        }
        if ($connection->isConnected()) {
            $connection->close();
        }
    }
    echo "RabbitMQ real unacknowledged connection loss, redelivery and dead-letter routing passed\n";
}

function sqsFifoWorkload(): void
{
    [, $serializer] = messagingSetup();
    $client = new SqsClient(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:19324', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'http' => ['connect_timeout' => 3, 'timeout' => 10]]);
    $url = $client->createQueue(['QueueName' => 'orders.fifo', 'Attributes' => ['FifoQueue' => 'true', 'ContentBasedDeduplication' => 'false']])['QueueUrl'];
    $transport = new SqsTransport(new LabSqsBackend($client, $url, fifo: true), $serializer);
    try {
        for ($i = 1; $i <= 20; ++$i) {
            $sent = $transport->send(new Envelope(new LabJob('order-' . $i, $i)), 'orders');
            $transport->send($sent, 'orders');
        }
        for ($i = 1; $i <= 20; ++$i) {
            $deadline = hrtime(true) + 10_000_000_000;
            do {
                $messages = iterator_to_array($transport->receive('orders', 1, 3.0));
            } while ($messages === [] && hrtime(true) < $deadline);
            verify(count($messages) === 1 && $messages[0]->envelope()->message->id === 'order-' . $i, 'FIFO order or deduplication mismatch.');
            if ($i === 1) {
                verify(iterator_to_array($transport->receive('orders', 1, 3.0)) === [], 'Same group advanced before first acknowledgement.');
            }
            $transport->acknowledge($messages[0]);
        }
        verify(iterator_to_array($transport->receive('orders', 10)) === [], 'Repeated message IDs produced duplicate effects.');
    } finally {
        $client->deleteQueue(['QueueUrl' => $url]);
    }
    echo "SQS-compatible FIFO per-group ordering, in-flight blocking and stable-ID deduplication passed\n";
}
