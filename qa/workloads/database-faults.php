<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\DBException;
use Infocyph\DBLayer\Exceptions\MigrationException;
use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

function migrationFault(Connection $db): void
{
    $migration = new class implements Migration {
        public bool $fail = true;

        public function id(): string
        {
            return '202611250002_fault';
        }

        public function up(SchemaManager $schema, MigrationContext $context): void
        {
            if (!$schema->hasTable('lab_migration_fault')) {
                $schema->create('lab_migration_fault', static function (Blueprint $table): void {
                    $table->id();
                    $table->string('label', 80)->unique();
                });
            }
            $context->checkpoint();
            if ($this->fail && !$context->pretending) {
                throw new DomainException('Deliberate failure after schema mutation.');
            }
        }

        public function down(SchemaManager $schema, MigrationContext $context): void
        {
            $schema->drop('lab_migration_fault');
        }
    };
    $schema = new SchemaManager($db);
    $runner = new MigrationRunner($db, [$migration]);
    try {
        $runner->run();
        throw new LogicException('Failed migration was accepted.');
    } catch (MigrationException) {
        // Expected injected failure, not a successful ledger entry.
    }
    check($runner->status()[0]['applied'] === false && $db->transactionLevel() === 0, 'Failed migration left applied or transaction state.');
    check($schema->hasTable('lab_migration_fault') === !$schema->supportsTransactionalDdl(), 'Driver-specific DDL failure boundary mismatch.');
    $migration->fail = false;
    check($runner->run() === [$migration->id()] && $runner->run() === [], 'Resumable migration failed to reconcile.');
    $db->table('lab_migration_fault')->insert(['label' => 'durable']);
    try {
        $runner->fresh();
        throw new LogicException('Destructive migration accepted without authorization.');
    } catch (MigrationException) {
        // Explicit authorization is required.
    }
    check($db->table('lab_migration_fault')->count() === 1, 'Denied migration damaged data.');
    check($runner->rollback() === [$migration->id()] && !$schema->hasTable('lab_migration_fault'), 'Recovery rollback failed.');
    check($runner->run() === [$migration->id()], 'Reapply after recovery failed.');
    echo "Migration failure, driver-specific DDL atomicity, recovery, authorization and ledger reconciliation passed\n";
}

function connectionLoss(Connection $db): void
{
    setup($db);
    $peer = new Connection(config());
    $driver = $db->getDriverName();
    $pid = (int) $db->getPdo()->query($driver === 'pgsql' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()')->fetchColumn();
    check($pid > 0, 'Missing database connection identity.');
    $attempts = 0;
    $effects = 0;
    try {
        $db->transaction(static function (Connection $active) use ($peer, $driver, $pid, &$attempts, &$effects): void {
            ++$attempts;
            $active->table('lab_balances')->where('id', '=', 1)->update(['balance' => 999]);
            $active->afterCommit(static function () use (&$effects): void { ++$effects; });
            if ($driver === 'pgsql') {
                check((bool) $peer->scalar('SELECT pg_terminate_backend(?)', [$pid]), 'Could not terminate fixture connection.');
            } else {
                $peer->statement('KILL CONNECTION ' . $pid);
            }
            $active->table('lab_balances')->where('id', '=', 2)->update(['balance' => 999]);
        }, attempts: 3);
        throw new LogicException('Lost transaction was reported as committed.');
    } catch (DBException) {
        // Real connection termination must not replay the transactional write.
    }
    check($attempts === 1 && $effects === 0, 'Lost transaction replayed or published callback.');
    check(balance($peer, 1) === 100 && balance($peer, 2) === 100, 'Disconnected transaction left partial effects.');
    $db->reconnect(true);
    check($db->transactionLevel() === 0 && balance($db, 1) === 100, 'Connection recovery retained broken state.');
    $db->transaction(static fn(Connection $active) => $active->table('lab_balances')->where('id', '=', 1)->update(['balance' => 101]));
    check(balance($peer, 1) === 101, 'Post-disconnect write did not recover.');
    echo "Real connection termination, no transaction replay, rollback and explicit recovery passed\n";
}

function mysqlProxyWorker(int $worker): void
{
    check($worker >= 1 && $worker <= 8, 'Invalid worker.');
    $db = new Connection(config(['port' => 16033]));
    $effects = 0;
    for ($i = 1; $i <= 50; ++$i) {
        $id = $worker * 1000 + $i;
        $db->transaction(static function (Connection $conn) use ($worker, $i, $id, &$effects): void {
            $backend = (int) $conn->scalar('SELECT CONNECTION_ID()');
            $conn->table('lab_proxy_ledger')->insert(['id' => $id, 'worker' => $worker, 'sequence' => $i]);
            $conn->beginTransaction();
            $conn->table('lab_proxy_ledger')->where('id', '=', $id)->update(['sequence' => -1]);
            $conn->rollbackTransaction();
            check((int) $conn->table('lab_proxy_ledger')->where('id', '=', $id)->value('sequence') === $i, 'Proxy savepoint mismatch.');
            check((int) $conn->scalar('SELECT CONNECTION_ID()') === $backend, 'Proxy changed backend inside transaction.');
            $conn->afterCommit(static function () use (&$effects): void { ++$effects; });
        });
    }
    check($effects === 50 && $db->transactionLevel() === 0, 'Proxy callback reconciliation failed.');
    echo "Worker $worker: 50 commits passed\n";
}
