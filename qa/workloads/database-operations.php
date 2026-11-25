<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\DBLayer\Exceptions\DBException;

function lockWait(Connection $db): void
{
    setup($db);
    $peer = new Connection(config());
    $peer->getPdo()->exec($db->getDriverName() === 'pgsql' ? "SET lock_timeout = '500ms'" : 'SET innodb_lock_wait_timeout = 1');
    $db->beginTransaction();
    try {
        $db->table('lab_balances')->where('id', '=', 1)->sharedLock()->first();
        check((int) $peer->table('lab_balances')->where('id', '=', 1)->value('balance') === 100, 'Shared lock prevented ordinary read.');
        $started = hrtime(true);
        rejected(static fn() => $peer->table('lab_balances')->where('id', '=', 1)->update(['balance' => 90]), 'Writer crossed active shared lock.');
        check(hrtime(true) - $started >= 200_000_000, 'Lock failure did not exercise a wait.');
        check($peer->table('lab_balances')->where('id', '=', 2)->update(['balance' => 110]) === 1, 'Unrelated row was locked.');
    } finally {
        $db->commitTransaction();
    }
    check($peer->table('lab_balances')->where('id', '=', 1)->update(['balance' => 90]) === 1, 'Released lock remained held.');
    check(balance($db, 1) === 90 && balance($db, 2) === 110, 'Lock wait changes did not reconcile.');
    echo "Shared lock, independent row, bounded lock wait and recovery passed\n";
}

function isolation(Connection $db): void
{
    setup($db);
    $peer = new Connection(config());
    $pgsql = $db->getDriverName() === 'pgsql';
    if (!$pgsql) {
        $db->getPdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }
    $db->beginTransaction();
    try {
        if ($pgsql) {
            $db->getPdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        check(balance($db, 1) === 100, 'Initial snapshot mismatch.');
        $peer->table('lab_balances')->where('id', '=', 1)->update(['balance' => 110]);
        $peer->table('lab_balances')->insert(['id' => 3, 'balance' => 5]);
        check(balance($db, 1) === 100 && $db->table('lab_balances')->count() === 2, 'Repeatable read allowed a changed snapshot.');
    } finally {
        $db->commitTransaction();
    }
    check(balance($db, 1) === 110 && $db->table('lab_balances')->count() === 3, 'Snapshot did not refresh after commit.');
    $peer->beginTransaction();
    try {
        $peer->table('lab_balances')->where('id', '=', 1)->update(['balance' => 999]);
        check(balance($db, 1) === 110, 'Uncommitted peer write visible.');
    } finally {
        $peer->rollbackTransaction();
    }
    check(balance($db, 1) === 110, 'Peer rollback changed durable balance.');
    echo "Repeatable snapshot, phantom prevention and dirty-read rejection passed\n";
}

function wal(Connection $db): void
{
    check(strtolower((string) $db->getPdo()->query('PRAGMA journal_mode = WAL')->fetchColumn()) === 'wal', 'WAL not active.');
    $db->getPdo()->exec('PRAGMA busy_timeout = 500');
    setup($db);
    $peer = new Connection(config());
    $peer->getPdo()->exec('PRAGMA busy_timeout = 500');
    $db->beginTransaction();
    try {
        $db->table('lab_balances')->where('id', '=', 1)->update(['balance' => 90]);
        check(balance($peer, 1) === 100, 'WAL reader could not read committed snapshot.');
        rejected(static fn() => $peer->table('lab_balances')->where('id', '=', 2)->update(['balance' => 110]), 'WAL permitted two writers.');
    } finally {
        $db->commitTransaction();
    }
    check(balance($peer, 1) === 90, 'WAL reader did not observe commit.');
    $peer->table('lab_balances')->where('id', '=', 2)->update(['balance' => 110]);
    check(balance($db, 2) === 110, 'WAL second writer did not recover.');
    echo "SQLite WAL, concurrent reader, writer contention and recovery passed\n";
}

function poolLifecycle(Connection $db): void
{
    setup($db);
    $pool = new Pool(['min_connections' => 0, 'max_connections' => 1, 'idle_timeout' => 1, 'health_check_interval' => 1]);
    $pool->addConfig('lab', config());
    $manager = new PoolManager($pool);
    try {
        $lease = $manager->checkout('lab');
        $first = $lease->connection();
        check((int) $first->scalar('SELECT 1') === 1, 'Initial lease failed.');
        $lease->release();
        sleep(2);
        $pool->healthCheck();
        $lease = $manager->checkout('lab');
        check($lease->connection() !== $first, 'Expired idle wrapper retained.');
        $connection = $lease->connection();
        $iterator = $connection->table('lab_balances')->orderBy('id')->unbufferedStream();
        $iterator->rewind();
        check($iterator->valid(), 'Pool stream did not start.');
        $lease->release();
        $replacement = $manager->checkout('lab');
        check($replacement->connection() !== $connection, 'Active stream wrapper reused.');
        unset($iterator);
        $replacement->release();
        try {
            $manager->using('lab', static function (Connection $conn): never {
                $conn->setQueryTimeoutMs(5000);
                throw new DomainException('Intentional callback failure');
            });
            throw new RuntimeException('Pool swallowed callback exception.');
        } catch (DomainException) {
            // Host callback failure must not leak its lease.
        }
        $manager->using('lab', static function (Connection $conn): void {
            check($conn->getQueryTimeoutMs() === null && (int) $conn->scalar('SELECT 1') === 1, 'Failed callback leaked state.');
        });
        try {
            $manager->using('lab', static fn(Connection $conn) => $conn->table('lab_balances')->cursor());
            throw new RuntimeException('Pool allowed a deferred cursor to escape its lease.');
        } catch (DBException | LogicException) {
            // Documented callback boundary rejection.
        }
        echo json_encode($pool->getStats(), JSON_THROW_ON_ERROR), PHP_EOL;
    } finally {
        $pool->closeAll();
    }
}

function budgets(Connection $db): void
{
    setup($db);
    $db->withQueryTimeoutMs(300, static function () use ($db): void {
        check($db->getQueryTimeoutMs() === 300, 'Timeout scope not applied.');
        $db->withQueryTimeoutMs(100, static function () use ($db): void {
            check($db->getQueryTimeoutMs() === 100, 'Nested timeout not applied.');
        });
        check($db->getQueryTimeoutMs() === 300, 'Nested timeout not restored.');
    });
    check($db->getQueryTimeoutMs() === null, 'Timeout leaked after scope.');
    rejected(static fn() => $db->withQueryCancellation(static fn(): bool => true, static fn() => $db->table('lab_balances')->where('id', '=', 1)->update(['balance' => 999])), 'Cancelled write executed.');
    rejected(static fn() => $db->withQueryDeadline(0.0, static fn() => $db->table('lab_balances')->where('id', '=', 1)->update(['balance' => 999])), 'Expired deadline permitted write.');
    check(balance($db, 1) === 100, 'Cancelled or expired operation mutated data.');
    $before = $db->getStats();
    $db->table('lab_balances')->where('id', '=', 1)->update(['balance' => 101]);
    check(balance($db, 1) === 101 && $db->getStats()['queries'] > $before['queries'], 'Query metrics or budget reset failed.');
    $db->reconnect(true);
    check(balance($db, 1) === 101, 'Explicit reconnect did not preserve durable data.');
    echo "Nested budget restoration, cancelled/deadline write safety, statistics and reconnect passed\n";
}

function replicaLag(Connection $primary): void
{
    setup($primary);
    $standby = new Connection(config(['port' => 5433]));
    waitFor(static function () use ($standby): bool {
        try {
            return balance($standby, 1) === 100;
        } catch (DBException) {
            return false;
        }
    }, 'Standby seed did not replay.', 40);
    $pdo = $standby->getPdo();
    $pdo->query('SELECT pg_wal_replay_pause()')->closeCursor();
    try {
        waitFor(static fn(): bool => $pdo->query('SELECT pg_get_wal_replay_pause_state()')->fetchColumn() === 'paused', 'WAL did not pause.');
        $primary->table('lab_balances')->where('id', '=', 1)->update(['balance' => 111]);
        $routed = routed('round_robin', [['port' => 5433, 'weight' => 1]], true);
        check(serverPort($routed) === 5433 && balance($routed, 1) === 100, 'Paused replica did not expose expected lag.');
        $routed->table('lab_balances')->where('id', '=', 1)->update(['balance' => 112]);
        check(serverPort($routed) === 5432 && balance($routed, 1) === 112, 'Sticky routing failed under actual replay lag.');
        check(balance($standby, 1) === 100, 'Replica unexpectedly replayed while paused.');
    } finally {
        $pdo->query('SELECT pg_wal_replay_resume()')->closeCursor();
    }
    waitFor(static fn(): bool => balance($standby, 1) === 112, 'Standby did not catch up after resume.', 40);
    echo "Physical replay lag and sticky read consistency passed\n";
}

function replicaPartial(): void
{
    $routed = routed('round_robin', [['port' => 5433, 'weight' => 1], ['port' => 5434, 'weight' => 1]]);
    check(serverPort($routed) === 5434, 'Failed reader did not select healthy replica.');
    waitFor(static fn(): bool => balance($routed, 1) === 112, 'Healthy replica did not reconcile durable update.', 40);
    $routed->reconnect(false);
    check(serverPort($routed) === 5434, 'Reconnect selected unavailable reader.');
    echo "Actual partial standby outage and healthy alternative passed\n";
}

function replicaRecover(): void
{
    $routed = routed('round_robin', [['port' => 5433, 'weight' => 1]]);
    check(serverPort($routed) === 5433, 'Restarted reader not selected by new execution.');
    waitFor(static fn(): bool => balance($routed, 1) === 112, 'Restarted standby data mismatch.', 40);
    echo "Restarted physical standby selected and reconciled\n";
}


function promotionSeed(Connection $primary): void
{
    setup($primary);
    $primary->statement('CREATE TABLE lab_promotion_ledger (id VARCHAR(40) PRIMARY KEY, amount INTEGER NOT NULL)');
    $primary->transaction(static function (Connection $active): void {
        $active->table('lab_promotion_ledger')->insert(['id' => 'before-promotion', 'amount' => 10]);
        $active->table('lab_balances')->where('id', '=', 1)->update(['balance' => 90]);
        $active->table('lab_balances')->where('id', '=', 2)->update(['balance' => 110]);
    });
    $lsn = (string) $primary->scalar('SELECT pg_current_wal_flush_lsn()::text');
    $standby = new Connection(config(['port' => 5433]));
    check((int) $standby->scalar('SELECT CASE WHEN pg_is_in_recovery() THEN 1 ELSE 0 END') === 1, 'Promotion candidate was not a standby.');
    waitFor(static fn(): bool => (int) $standby->scalar('SELECT CASE WHEN pg_last_wal_replay_lsn() >= CAST(? AS pg_lsn) THEN 1 ELSE 0 END', [$lsn]) === 1, 'Standby failed to replay committed WAL.', 45);
    check(balance($standby, 1) === 90 && balance($standby, 2) === 110 && $standby->table('lab_promotion_ledger')->count() === 1, 'Promotion candidate has not reconciled durable effects.');
    echo "Standby replay confirmed through durable commit boundary\n";
}

function promotionVerify(): void
{
    $replacement = new Connection(config(['port' => 5433]));
    check((int) $replacement->scalar('SELECT CASE WHEN pg_is_in_recovery() THEN 1 ELSE 0 END') === 0, 'Replacement endpoint is not writable primary.');
    check(balance($replacement, 1) === 90 && balance($replacement, 2) === 110, 'Promoted primary lost confirmed commit.');
    $callbacks = 0;
    $result = $replacement->transaction(static function (Connection $active) use (&$callbacks): string {
        $active->table('lab_balances')->where('id', '=', 1)->lockForUpdate()->first();
        if ($active->table('lab_promotion_ledger')->where('id', '=', 'before-promotion')->exists()) {
            return 'already-committed';
        }
        throw new LogicException('Idempotency identity disappeared after promotion.');
    });
    check($result === 'already-committed' && $callbacks === 0, 'Existing operation was replayed.');
    $replacement->transaction(static function (Connection $active) use (&$callbacks): void {
        $active->table('lab_promotion_ledger')->insert(['id' => 'after-promotion', 'amount' => 5]);
        $active->table('lab_balances')->where('id', '=', 1)->update(['balance' => 85]);
        $active->table('lab_balances')->where('id', '=', 2)->update(['balance' => 115]);
        $active->afterCommit(static function () use (&$callbacks): void { ++$callbacks; });
    });
    $fresh = new Connection(config(['port' => 5433]));
    check($callbacks === 1 && $replacement->transactionLevel() === 0
        && balance($fresh, 1) === 85 && balance($fresh, 2) === 115
        && $fresh->table('lab_promotion_ledger')->count() === 2, 'New primary write or independent reconciliation failed.');
    echo "Actual primary stop, physical standby promotion and explicit write-endpoint replacement passed\n";
}
