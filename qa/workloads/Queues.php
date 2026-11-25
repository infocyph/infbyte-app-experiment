<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Messaging\ConsumerFactory;
use Infocyph\Foundation\Messaging\MessagingDatabaseSchema;
use Infocyph\Omnibus\Integration\DBLayer\AfterCommitDispatcher;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\MessageBus;

final readonly class Queues
{
    public function __construct(private Application $app) {}

    /** @throws \Throwable */
    public function setup(): void
    {
        $this->app->make(MessagingDatabaseSchema::class)->install();
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $db->statement('CREATE TABLE experiment_receipts (id VARCHAR(80) PRIMARY KEY)');
        $db->statement('CREATE TABLE experiment_attempts (id VARCHAR(80) PRIMARY KEY, attempts INTEGER NOT NULL)');
    }

    /** @throws \Throwable */
    public function execute(string $id): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $dispatcher = $this->app->make(AfterCommitDispatcher::class);
        $db->transaction(static function () use ($dispatcher, $id): void {
            $dispatcher->dispatch(new Receipt($id . '-normal', 'normal'));
            $dispatcher->dispatch(new Receipt($id . '-normal', 'normal'));
            $dispatcher->dispatch(new Receipt($id . '-retry', 'retry'));
            $dispatcher->dispatch(new Receipt($id . '-fail', 'fail'));
        });
        try {
            $db->transaction(static function () use ($dispatcher, $id): never {
                $dispatcher->dispatch(new Receipt($id . '-rollback', 'rollback'));
                throw new \DomainException('intentional rollback');
            });
        } catch (\Infocyph\DBLayer\Exceptions\TransactionException $exception) {
            ensure($exception->getPrevious() instanceof \DomainException && $exception->getPrevious()->getMessage() === 'intentional rollback', 'Unexpected dispatch failure.');
        }
    }

    /** @throws \Throwable */
    public function drain(): void
    {
        $consumer = $this->app->make(ConsumerFactory::class)->make('database');
        $until = hrtime(true) + 120_000_000_000;
        $idle = 0;
        while (hrtime(true) < $until) {
            $result = $consumer->run('receipts', 8, 10.0);
            if ($result->received > 0) {
                $idle = 0;
                continue;
            }
            if (++$idle >= 20) {
                return;
            }
            usleep(100_000);
        }
        throw new \RuntimeException('Durable queue drain exceeded the time budget.');
    }

    /** @throws \Throwable */
    public function verify(int $expected): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        ensure($this->app->make(DBLayerTransport::class)->size('receipts') === 0, 'Undelivered messages.');
        ensure(count($this->app->make(DBLayerFailureStore::class)->all()) === $expected, 'Dead-letter or rolled-back dispatch count mismatch.');
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM experiment_receipts')) === $expected * 2, 'Lost or duplicate durable business effect.');
        ensure(countValue($db->scalar("SELECT COUNT(*) FROM experiment_attempts WHERE attempts = 2")) === $expected * 2, 'Duplicate and retry attempts mismatch.');
    }
}
