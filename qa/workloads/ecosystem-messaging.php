<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerWorkflowStore;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;
use Infocyph\Omnibus\Integration\Redis\CallbackRedisClient;
use Infocyph\Omnibus\Integration\Redis\RedisTransport;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Infocyph\Omnibus\Workflow\WorkflowCoordinator;
use Infocyph\Omnibus\Workflow\WorkflowExecutionScope;
use Infocyph\Omnibus\Workflow\WorkflowFailureStore;
use Infocyph\Omnibus\Workflow\WorkflowTransport;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Email;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Enum\SmtpSecurity;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;

final readonly class LabJob
{
    public function __construct(public string $id, public int $step) {}
}

/** @return array{Connection,JsonEnvelopeSerializer,HandlerInvoker,SystemClock} */
function messagingSetup(bool $initialize = true): array
{
    $driver = (string) getenv('DB_BACKEND');
    $config = match ($driver) {
        'sqlite' => ['driver' => 'sqlite', 'database' => evidenceDirectory() . '/messaging.sqlite'],
        'mssql' => ['driver' => 'mssql', 'host' => '127.0.0.1', 'port' => 1433, 'database' => 'experiment',
            'username' => 'sa', 'password' => 'Experiment-Only-42!', 'encrypt' => true, 'trust_server_certificate' => true],
        'pgsql', 'mysql', 'mariadb' => ['driver' => $driver, 'host' => '127.0.0.1',
            'port' => match ($driver) { 'pgsql' => 5432, 'mariadb' => 3307, default => 3306 },
            'database' => 'experiment', 'username' => 'experiment', 'password' => 'experiment'],
        default => throw new InvalidArgumentException('Unsupported messaging SQL backend.'),
    };
    $db = new Connection(ConnectionConfig::fromArray($config));
    if ($driver === 'sqlite') {
        $db->getPdo()->exec('PRAGMA foreign_keys = ON');
        $db->getPdo()->exec('PRAGMA busy_timeout = 5000');
    }
    if ($initialize) {
        foreach (QueueSchema::statements($driver) as $statement) {
            $db->statement($statement);
        }
        $db->statement('CREATE TABLE lab_job_effects (id VARCHAR(100) PRIMARY KEY, step INTEGER NOT NULL)');
    }
    $serializer = new JsonEnvelopeSerializer(
        new MessageCodecRegistry([
            new CallbackMessageCodec('lab.job.v1', LabJob::class,
                static fn(LabJob $job): array => ['id' => $job->id, 'step' => $job->step],
                static fn(array $data): LabJob => new LabJob((string) $data['id'], (int) $data['step']),
            ),
        ]),
        new StampCodecRegistry(CoreStampCodecs::all()),
    );
    $invoker = new HandlerInvoker(new HandlerMap([
        LabJob::class => static function (LabJob $job) use ($db): void {
            if (str_starts_with($job->id, 'chain-') && $job->step > 1) {
                verify($db->table('lab_job_effects')->where('id', '=', 'chain-' . ($job->step - 1))->exists(), 'Chain ran out of order.');
            }
            $db->table('lab_job_effects')->insert(['id' => $job->id, 'step' => $job->step]);
        },
    ]));

    return [$db, $serializer, $invoker, new SystemClock()];
}

function omnibusWorkflowWorkload(): void
{
    [$db, $serializer, $invoker, $clock] = messagingSetup();
    $queue = new DBLayerTransport($db, $serializer, $clock);
    $store = new DBLayerWorkflowStore($db, $serializer);
    $coordinator = new WorkflowCoordinator($store, $queue);
    $failures = new DBLayerFailureStore($db, $serializer);
    $consumer = new Consumer(
        new WorkflowTransport($queue, $coordinator),
        $invoker,
        new ExponentialRetryStrategy(maximumAttempts: 1),
        new WorkflowFailureStore($failures, $coordinator),
        $clock,
        new WorkflowExecutionScope(new DirectExecutionScope(), $store),
    );
    $chain = $coordinator->chain([new LabJob('chain-1', 1), new LabJob('chain-2', 2), new LabJob('chain-3', 3)], queue: 'orders');
    verify($queue->size('orders') === 1, 'Chain dispatched more than its first item.');
    $jobs = [];
    for ($i = 1; $i <= 120; ++$i) {
        $jobs[] = new LabJob('batch-' . $i, $i);
    }
    $batch = $coordinator->batch($jobs, queue: 'orders');
    $cancelled = $coordinator->batch([new LabJob('cancel-1', 1), new LabJob('cancel-2', 2)], queue: 'orders');
    $coordinator->cancel($cancelled);
    for ($i = 0; $i < 100 && $queue->size('orders') > 0; ++$i) {
        $consumer->run('orders', 12, 30.0);
    }
    verify($queue->size('orders') === 0 && $db->table('lab_job_effects')->count() === 123, 'Workflow effects/queue reconciliation failed.');
    foreach ([[$chain, 3], [$batch, 120]] as [$id, $expected]) {
        $state = $db->table('omnibus_workflows')->find($id);
        verify($state['workflow_status'] === 'completed' && (int) $state['succeeded'] === $expected, 'Workflow terminal state mismatch.');
    }
    $state = $db->table('omnibus_workflows')->find($cancelled);
    verify($state['workflow_status'] === 'cancelled' && (int) $state['cancelled'] === 2, 'Cancellation state mismatch.');
    verify(count($failures->all(100)) === 0, 'Unexpected workflow failures.');
    echo "Durable ordered chain, 120-item batch, cancellation, settlement and exact effects passed\n";
}

function omnibusRedisWorkload(): void
{
    [$db, $serializer, $invoker, $clock] = messagingSetup();
    $redis = new Redis();
    verify($redis->connect('127.0.0.1', 6379, 2.0), 'Redis connection failed.');
    $queue = new RedisTransport(new CallbackRedisClient(static fn(string $command, string ...$args): mixed => $redis->rawCommand($command, ...$args)), $serializer, $clock, prefix: 'lab-redis-jobs');
    $bus = new MessageBus(new RouteMap([LabJob::class => new Route(transport: 'redis', queue: 'jobs', delaySeconds: 1.0)]), new TransportRegistry(['redis' => $queue]));
    $failures = new DBLayerFailureStore($db, $serializer);
    $consumer = new Consumer($queue, $invoker, new ExponentialRetryStrategy(maximumAttempts: 1), $failures, $clock);
    for ($i = 1; $i <= 100; ++$i) {
        $bus->dispatch(new LabJob('redis-' . $i, $i));
    }
    $deadline = hrtime(true) + 30_000_000_000;
    $count = 0;
    while ($count < 100 && hrtime(true) < $deadline) {
        $result = $consumer->run('jobs', 12, 20.0);
        $count += $result->succeeded;
        if ($result->received === 0) {
            usleep(50000);
        }
    }
    verify($count === 100 && $db->table('lab_job_effects')->count() === 100 && $queue->size('jobs') === 0, 'Redis delayed queue reconciliation failed.');
    verify(count($failures->all(100)) === 0, 'Unexpected Redis queue dead letters.');
    echo "100 delayed Redis messages with durable SQL effect reconciliation passed\n";
}

function mailMimeWorkload(): void
{
    $sender = Email::sender()->usingSmtp(new SmtpConfig(host: '127.0.0.1', port: 1025, security: SmtpSecurity::None, timeoutSeconds: 10, localDomain: 'localhost'));
    $message = EmailMessage::new()
        ->from('billing@example.test')
        ->to('customer@example.test')
        ->cc('audit@example.test')
        ->bcc('internal@example.test')
        ->subject('Production laboratory MIME')
        ->text("Invoice line one\r\nInvoice line two")
        ->html('<p>Invoice</p><img src="cid:lab-logo">')
        ->attachData('invoice-content', 'invoice.txt', 'text/plain')
        ->attachInlineData('inline-fixture', 'logo.png', 'lab-logo', 'image/png');
    verify($sender->send($message)->successful, 'SMTP multipart send failed.');
    $handle = curl_init('http://127.0.0.1:8025/api/v1/message/latest/raw');
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $raw = null;
    $until = hrtime(true) + 10_000_000_000;
    do {
        $value = curl_exec($handle);
        if (is_string($value) && curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200) {
            $raw = $value;
            break;
        }
        usleep(100000);
    } while (hrtime(true) < $until);
    verify(is_string($raw) && !str_contains($raw, "\r\nBcc:") && str_contains(strtolower($raw), 'multipart/'), 'Raw MIME visibility mismatch.');
    $parsed = new RawEmailParser()->parse($raw);
    verify($parsed->subjectOrEmpty() === 'Production laboratory MIME' && $parsed->fromEmail() === 'billing@example.test' && $parsed->attachmentCount() >= 2, 'Inbound MIME parse mismatch.');
    echo "Real SMTP multipart/alternative, attachments, BCC privacy and raw MIME parsing passed\n";
}
