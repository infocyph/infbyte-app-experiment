<?php

declare(strict_types=1);

use Experiment\Receipt;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Messaging\ConsumerFactory;
use Infocyph\Foundation\Messaging\MessagingDatabaseSchema;
use Infocyph\Foundation\Messaging\OmnibusWorkerFactory;
use Infocyph\Omnibus\Failure\FailureStore;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Scheduling\MessageFactoryNotFound;
use Infocyph\Omnibus\Scheduling\ScheduledMessageDispatcher;

final readonly class LabConfiguredReceiptHandler
{
    public function __construct(private DBLayerFactory $database) {}

    public function __invoke(Receipt $message): void
    {
        if ($message->kind === 'fail') {
            throw new DomainException('configured permanent failure');
        }
        $db = $this->database->connection();
        verify($db->table('lab_messaging_effects')->insert(['id' => $message->id]), 'Receipt publication failed.');
    }
}

final class LabConfiguredReceiptFactory
{
    public function __invoke(): Receipt
    {
        return new Receipt('scheduled-receipt', 'normal');
    }
}

final class LabInvalidReceiptFactory
{
    public function __invoke(): string
    {
        return 'not a message';
    }
}

/** Verify a root exception without conflating container wrappers with configuration failures. */
function labMessagingReject(callable $operation, string $type, string $message): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof $type) {
                return;
            }
        }
        throw $exception;
    }

    throw new RuntimeException($message);
}

function foundationMessagingContracts(string $contract): void
{
    $tables = [
        'messages' => 'lab_omnibus_messages',
        'failures' => 'lab_omnibus_failures',
        'workflows' => 'lab_omnibus_workflows',
        'workflow_items' => 'lab_omnibus_workflow_items',
    ];
    $config = [
        'durable' => ['enabled' => true, 'connection' => 'main', 'failure_store' => $contract === 'memory-failures' ? 'memory' : 'database', 'tables' => $tables],
        'default_route' => ['transport' => 'database', 'queue' => 'lab-receipts'],
        'routes' => [Receipt::class => ['transport' => 'database', 'queue' => 'lab-receipts', 'delay_seconds' => 0.0]],
        'handlers' => [Receipt::class => LabConfiguredReceiptHandler::class],
        'consumer' => ['transport' => 'database'],
        'retry' => ['maximum_attempts' => 1, 'initial_delay_seconds' => 0.0, 'multiplier' => 1.0, 'maximum_delay_seconds' => 0.0, 'jitter_ratio' => 0.0],
        'workers' => [
            'bounded' => ['transport' => 'database', 'queue' => 'lab-receipts', 'prefetch' => '8', 'visibility_seconds' => '15',
                'max_messages' => '2', 'max_runtime_seconds' => '5', 'handle_signals' => false,
                'pool' => ['enabled' => true, 'concurrency' => '3', 'maximum_restarts' => '2', 'restart_backoff_seconds' => '0.1', 'shutdown_grace_seconds' => '4']],
            'one' => ['queue' => 'lab-receipts', 'max_messages' => 1, 'max_runtime_seconds' => 5, 'handle_signals' => false],
            'invalid' => ['queue' => 'lab-receipts', 'prefetch' => 0],
            'wrong-type' => ['queue' => 'lab-receipts', 'max_messages' => 'bad'],
        ],
        'scheduled_messages' => ['receipt' => LabConfiguredReceiptFactory::class, 'invalid' => LabInvalidReceiptFactory::class],
    ];
    $newApp = static fn() => foundationProviderApp(['messaging' => $config], ['database', 'messaging']);
    if ($contract === 'invalid-durable') {
        foreach ([['enabled' => false], ['failure_store' => ''], ['failure_store' => 'unsupported']] as $invalid) {
            $bad = array_replace_recursive($config, ['durable' => $invalid]);
            labMessagingReject(static fn() => foundationProviderApp(['messaging' => $bad], ['database', 'messaging']), ConfigurationException::class, 'Invalid durable messaging configuration booted.');
        }
        labMessagingReject(static fn() => foundationProviderApp(['messaging' => $config], ['messaging']), ConfigurationException::class, 'Durable messaging booted without database capability.');
    }
    $app = $newApp();
    $schema = $app->make(MessagingDatabaseSchema::class);
    verify($schema->readiness()['missing_tables'] === array_values($tables), 'New custom durable schema is not empty.');
    if ($contract === 'partial-schema') {
        $app->execution()->run(static function () use ($app, $tables): void {
            $db = $app->make(DBLayerFactory::class)->connection();
            $db->statement('CREATE TABLE ' . $tables['messages'] . ' (id INTEGER PRIMARY KEY)');
        });
        $partial = $schema->readiness();
        verify(!$partial['installed'] && $partial['installed_tables'] === [$tables['messages']], 'Partial durable schema was accepted.');
        expectFailure($schema->install(...), ConfigurationException::class, 'Partial schema silently repaired.');
        verify($schema->readiness() === $partial, 'Rejected cutover changed other durable tables.');
        $app->execution()->run(static fn() => $app->make(DBLayerFactory::class)->connection()->statement('DROP TABLE ' . $tables['messages']));
    }
    $schema->install();
    $schema->install();
    verify($schema->readiness()['installed_tables'] === array_values($tables) && $schema->readiness()['installed'], 'Custom durable schema installation differs.');
    $app->execution()->run(static function () use ($app): void {
        $db = $app->make(DBLayerFactory::class)->connection();
        $db->statement('CREATE TABLE lab_messaging_effects (id VARCHAR(80) PRIMARY KEY)');
    });
    $effects = static fn($application): array => $application->execution()->run(static function () use ($application): array {
        $rows = $application->make(DBLayerFactory::class)->connection()->select('SELECT id FROM lab_messaging_effects ORDER BY id');

        return array_column($rows, 'id');
    });
    $transport = $app->make(DBLayerTransport::class);
    $factory = $app->make(OmnibusWorkerFactory::class);
    $publish = static function ($application, array $messages): void {
        $application->execution()->run(static function () use ($application, $messages): void {
            $bus = $application->make(MessageBus::class);
            verify($bus === $application->make('foundation.messaging'), 'MessageBus alias identity differs.');
            foreach ($messages as $message) {
                $bus->dispatch($message);
            }
        });
    };
    if ($contract === 'worker-options') {
        verify($factory->has('bounded') && !$factory->has('missing'), 'Worker registry lookup differs.');
        $options = $factory->options('bounded');
        verify($options->queue === 'lab-receipts' && $options->prefetch === 8 && $options->visibilitySeconds === 15.0 && $options->maxMessages === 2 && !$options->handleSignals, 'Resolved worker options differ.');
        verify($factory->transport('one') === 'database' && $factory->pool('bounded') === [
            'enabled' => true, 'concurrency' => 3, 'maximum_restarts' => 2, 'restart_backoff_seconds' => 0.1, 'shutdown_grace_seconds' => 4.0,
        ], 'Configured worker transport or pool options differ.');
        foreach (['missing', 'invalid', 'wrong-type'] as $name) {
            expectFailure(static fn() => $factory->options($name), InvalidArgumentException::class, 'Invalid worker option accepted.');
        }
        expectFailure(static fn() => $app->make(ConsumerFactory::class)->make('sync'), LogicException::class, 'Send-only transport accepted as consumer.');
        verify($effects($app) === [] && $transport->size('lab-receipts') === 0, 'Configuration validation produced effects.');
    } elseif ($contract === 'worker-recycle') {
        $publish($app, [new Receipt('receipt-a', 'normal'), new Receipt('receipt-b', 'normal'), new Receipt('receipt-c', 'normal')]);
        $factory->make('bounded')->run();
        verify(count($effects($app)) === 2 && $transport->size('lab-receipts') === 1, 'Prefetch exceeded worker message limit.');
        $replacement = $newApp();
        $replacement->make(OmnibusWorkerFactory::class)->make('one')->run();
        verify($effects($replacement) === ['receipt-a', 'receipt-b', 'receipt-c'] && $replacement->make(DBLayerTransport::class)->size('lab-receipts') === 0, 'Replacement failed durable drain.');
    } elseif ($contract === 'scheduled') {
        $app->execution()->run(static function () use ($app): void {
            $dispatcher = $app->make(ScheduledMessageDispatcher::class);
            expectFailure(static fn() => $dispatcher->dispatch('missing'), MessageFactoryNotFound::class, 'Unknown scheduled factory accepted.');
            expectFailure(static fn() => $dispatcher->dispatch('invalid'), UnexpectedValueException::class, 'Scalar scheduled factory accepted.');
            $dispatcher->dispatch('receipt');
        });
        verify($transport->size('lab-receipts') === 1 && $effects($app) === [], 'Scheduled publication or async boundary differs.');
        $replacement = $newApp();
        $replacement->make(OmnibusWorkerFactory::class)->make('one')->run();
        verify($effects($replacement) === ['scheduled-receipt'] && $replacement->make(DBLayerTransport::class)->size('lab-receipts') === 0, 'Replacement scheduled dispatch did not settle.');
    } elseif (in_array($contract, ['database-failures', 'memory-failures'], true)) {
        $store = $app->make(FailureStore::class);
        $expected = $contract === 'database-failures' ? DBLayerFailureStore::class : InMemoryFailureStore::class;
        verify($store instanceof $expected, 'Configured failure store differs.');
        $publish($app, [new Receipt('failed-receipt', 'fail')]);
        $factory->make('one')->run();
        $failures = $store->all(10);
        verify(count($failures) === 1 && $failures[0]->envelope?->message instanceof Receipt && $failures[0]->envelope->message->id === 'failed-receipt', 'Failure store lost business identity.');
        verify($transport->size('lab-receipts') === 0 && $effects($app) === [], 'Failed receipt remained queued or published effect.');
        $replacement = $newApp();
        $expectedCount = $contract === 'database-failures' ? 1 : 0;
        verify(count($replacement->make(FailureStore::class)->all(10)) === $expectedCount, 'Failure durability policy changed across replacement.');
    } else {
        $publish($app, [new Receipt('recovered-receipt', 'normal')]);
        $factory->make('one')->run();
        verify($effects($app) === ['recovered-receipt'] && $transport->size('lab-receipts') === 0, 'Valid durable generation failed after configuration/schema recovery.');
    }
    echo "Foundation configured messaging $contract passed\n";
}
