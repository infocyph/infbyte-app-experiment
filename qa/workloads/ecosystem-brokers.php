<?php

declare(strict_types=1);

use Aws\Sqs\SqsClient;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\AMQP\AmqpBackend;
use Infocyph\Omnibus\Integration\AMQP\AmqpTransport;
use Infocyph\Omnibus\Integration\Broker\BrokerCapabilities;
use Infocyph\Omnibus\Integration\Broker\BrokerDelivery;
use Infocyph\Omnibus\Integration\Broker\UnsupportedBrokerCapability;
use Infocyph\Omnibus\Integration\SQS\SqsBackend;
use Infocyph\Omnibus\Integration\SQS\SqsTransport;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

final readonly class LabAmqpBackend implements AmqpBackend
{
    public function __construct(private AMQPChannel $channel, private ?Closure $ackFault = null) {}

    public function capabilities(): BrokerCapabilities
    {
        return new BrokerCapabilities(false, false, true, 100);
    }

    public function send(string $queue, string $messageId, string $payload, float $delaySeconds): void
    {
        $this->channel->basic_publish(new AMQPMessage($payload, ['message_id' => $messageId, 'delivery_mode' => 2]), '', $queue, true);
    }

    public function receive(string $queue, int $limit, float $visibilitySeconds): array
    {
        $deliveries = [];
        for ($i = 0; $i < $limit; ++$i) {
            $message = $this->channel->basic_get($queue);
            if ($message === null) {
                break;
            }
            $deliveries[] = new BrokerDelivery((string) $message->getDeliveryTag(), $message->get('message_id'), $message->getBody(), $message->isRedelivered() ? 2 : 1);
        }

        return $deliveries;
    }

    public function acknowledge(string $queue, string $receipt): void
    {
        ($this->ackFault)?->__invoke('before');
        $this->channel->basic_ack((int) $receipt);
        if ($this->ackFault !== null) {
            // A synchronous broker round trip orders the earlier asynchronous ack.
            $this->channel->queue_declare($queue, true);
            ($this->ackFault)('after');
        }
    }

    public function reject(string $queue, string $receipt): void
    {
        $this->channel->basic_reject((int) $receipt, false);
    }

    public function release(string $queue, string $receipt, float $delaySeconds): void
    {
        $this->channel->basic_reject((int) $receipt, true);
    }

    public function size(string $queue): int
    {
        return $this->channel->queue_declare($queue, true)[1];
    }
}

final readonly class LabSqsBackend implements SqsBackend
{
    public function __construct(private SqsClient $client, private string $url, private bool $fifo = false, private ?Closure $ackFault = null) {}

    public function capabilities(): BrokerCapabilities
    {
        return new BrokerCapabilities(!$this->fifo, true, false, 10);
    }

    public function send(string $queue, string $messageId, string $payload, float $delaySeconds): void
    {
        $request = ['QueueUrl' => $this->url, 'MessageBody' => $payload, 'MessageAttributes' => ['LabId' => ['DataType' => 'String', 'StringValue' => $messageId]]];
        if ($this->fifo) {
            $request['MessageGroupId'] = 'orders';
            $request['MessageDeduplicationId'] = $messageId;
        } else {
            $request['DelaySeconds'] = (int) ceil($delaySeconds);
        }
        $this->client->sendMessage($request);
    }

    public function receive(string $queue, int $limit, float $visibilitySeconds): array
    {
        $result = $this->client->receiveMessage(['QueueUrl' => $this->url, 'MaxNumberOfMessages' => $limit, 'VisibilityTimeout' => (int) ceil($visibilitySeconds), 'WaitTimeSeconds' => 1, 'AttributeNames' => ['ApproximateReceiveCount'], 'MessageAttributeNames' => ['LabId']]);

        return array_map(static fn(array $message): BrokerDelivery => new BrokerDelivery($message['ReceiptHandle'], $message['MessageAttributes']['LabId']['StringValue'], $message['Body'], (int) $message['Attributes']['ApproximateReceiveCount']), $result['Messages'] ?? []);
    }

    public function acknowledge(string $queue, string $receipt): void
    {
        ($this->ackFault)?->__invoke('before');
        $this->client->deleteMessage(['QueueUrl' => $this->url, 'ReceiptHandle' => $receipt]);
        ($this->ackFault)?->__invoke('after');
    }

    public function reject(string $queue, string $receipt): void
    {
        $this->acknowledge($queue, $receipt);
    }

    public function release(string $queue, string $receipt, float $delaySeconds): void
    {
        $this->client->changeMessageVisibility(['QueueUrl' => $this->url, 'ReceiptHandle' => $receipt, 'VisibilityTimeout' => (int) ceil($delaySeconds)]);
    }

    public function size(string $queue): int
    {
        return (int) $this->client->getQueueAttributes(['QueueUrl' => $this->url, 'AttributeNames' => ['ApproximateNumberOfMessages']])['Attributes']['ApproximateNumberOfMessages'];
    }
}

function amqpWorkload(): void
{
    [, $serializer] = messagingSetup();
    $connection = new AMQPStreamConnection('127.0.0.1', 15672, 'experiment', 'fixture-only-password');
    $channel = $connection->channel();
    try {
        $channel->queue_declare('jobs', false, true, false, false);
        $transport = new AmqpTransport(new LabAmqpBackend($channel), $serializer);
        expectFailure(static fn() => $transport->send(new Envelope(new LabJob('unsupported', 0), [new DelayStamp(2.0)]), 'jobs'), UnsupportedBrokerCapability::class, 'Unsupported AMQP delay accepted.');
        for ($i = 1; $i <= 50; ++$i) {
            $transport->send(new Envelope(new LabJob('amqp-' . $i, $i)), 'jobs');
        }
        verify($transport->size('jobs') === 50, 'AMQP ready queue size mismatch.');
        $first = iterator_to_array($transport->receive('jobs', 1))[0];
        $transport->release($first);
        $second = iterator_to_array($transport->receive('jobs', 1))[0];
        verify($second->messageId === $first->messageId && $second->attempt === 2, 'AMQP requeue identity/attempt mismatch.');
        $transport->acknowledge($second);
        $seen = [$second->envelope()->message->id => true];
        foreach ($transport->receive('jobs', 49) as $delivery) {
            $id = $delivery->envelope()->message->id;
            verify(!isset($seen[$id]), 'AMQP duplicate effect.');
            $seen[$id] = true;
            $transport->acknowledge($delivery);
        }
        verify(count($seen) === 50 && $transport->size('jobs') === 0, 'AMQP acknowledgement reconciliation failed.');
        $transport->send(new Envelope(new LabJob('reject', 0)), 'jobs');
        $transport->reject(iterator_to_array($transport->receive('jobs', 1))[0]);
        verify($transport->size('jobs') === 0, 'AMQP rejection retained message.');
    } finally {
        $channel->close();
        $connection->close();
    }
    echo "Real RabbitMQ application adapter, unsupported delay, reservation/requeue/ack/reject passed\n";
}

function sqsWorkload(): void
{
    [, $serializer] = messagingSetup();
    $client = new SqsClient(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:19324', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'http' => ['connect_timeout' => 3, 'timeout' => 10]]);
    $url = $client->createQueue(['QueueName' => 'lab-jobs'])['QueueUrl'];
    $transport = new SqsTransport(new LabSqsBackend($client, $url), $serializer);
    try {
        expectFailure(static fn() => iterator_to_array($transport->receive('jobs', 11)), InvalidArgumentException::class, 'SQS batch bound bypassed.');
        $transport->send(new Envelope(new LabJob('delayed', 0), [new DelayStamp(3.0)]), 'jobs');
        verify(iterator_to_array($transport->receive('jobs', 1)) === [], 'Delayed SQS delivery appeared prematurely.');
        $deadline = hrtime(true) + 15_000_000_000;
        do {
            $deliveries = iterator_to_array($transport->receive('jobs', 1, 2.0));
        } while ($deliveries === [] && hrtime(true) < $deadline);
        verify(count($deliveries) === 1, 'Delayed SQS delivery missing.');
        $first = $deliveries[0];
        $transport->release($first, 2.0);
        verify(iterator_to_array($transport->receive('jobs', 1)) === [], 'Released SQS message ignored visibility delay.');
        do {
            $deliveries = iterator_to_array($transport->receive('jobs', 1, 10.0));
        } while ($deliveries === [] && hrtime(true) < $deadline);
        verify(count($deliveries) === 1 && $deliveries[0]->messageId === $first->messageId && $deliveries[0]->attempt >= 2 && $deliveries[0]->receipt !== $first->receipt, 'SQS visibility/receipt identity mismatch.');
        $transport->acknowledge($deliveries[0]);
        for ($i = 1; $i <= 50; ++$i) {
            $transport->send(new Envelope(new LabJob('sqs-' . $i, $i)), 'jobs');
        }
        $seen = [];
        $deadline = hrtime(true) + 30_000_000_000;
        while (count($seen) < 50 && hrtime(true) < $deadline) {
            foreach ($transport->receive('jobs', 10, 20.0) as $delivery) {
                $id = $delivery->envelope()->message->id;
                verify(!isset($seen[$id]), 'Unexpected duplicate in isolated SQS fixture.');
                $seen[$id] = true;
                $transport->acknowledge($delivery);
            }
        }
        verify(count($seen) === 50 && iterator_to_array($transport->receive('jobs', 10)) === [], 'SQS acknowledgement reconciliation failed.');
    } finally {
        $client->deleteQueue(['QueueUrl' => $url]);
    }
    echo "Real SQS-compatible service via application adapter, delay, visibility, batch and receipts passed\n";
}
