<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\TransactionException;

require getenv('PROJECT') . '/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string,mixed> $overrides */
function config(array $overrides = []): ConnectionConfig
{
    $driver = (string) getenv('DB_BACKEND');

    if ($driver === 'sqlite') {
        return ConnectionConfig::fromArray(array_replace([
            'driver' => 'sqlite', 'database' => getenv('EVIDENCE') . '/db.sqlite',
        ], $overrides));
    }

    return ConnectionConfig::fromArray(array_replace([
        'driver' => $driver,
        'host' => '127.0.0.1',
        'port' => $driver === 'pgsql' ? 5432 : 3306,
        'database' => 'experiment',
        'username' => 'experiment',
        'password' => 'experiment',
    ], $overrides));
}

function waitFor(callable $predicate, string $message, int $seconds = 20): void
{
    $deadline = hrtime(true) + $seconds * 1_000_000_000;
    do {
        if ($predicate()) {
            return;
        }
        usleep(20_000);
    } while (hrtime(true) < $deadline);
    throw new RuntimeException($message);
}

function setup(Connection $db): void
{
    $db->statement('CREATE TABLE lab_balances (id INTEGER PRIMARY KEY, balance INTEGER NOT NULL)');
    $db->statement('INSERT INTO lab_balances (id, balance) VALUES (1, 100), (2, 100)');
}

function balance(Connection $db, int $id): int
{
    return (int) $db->scalar('SELECT balance FROM lab_balances WHERE id = ?', [$id]);
}

function transactions(Connection $db): void
{
    setup($db);
    $effects = [];
    $db->transaction(function (Connection $db) use (&$effects): void {
        $db->table('lab_balances')->where('id', '=', 1)->update(['balance' => 90]);
        $db->afterCommit(static function () use (&$effects): void { $effects[] = 'outer'; });
        $db->beginTransaction();
        $db->table('lab_balances')->where('id', '=', 2)->update(['balance' => 999]);
        $db->afterCommit(static function () use (&$effects): void { $effects[] = 'discard'; });
        $db->rollbackTransaction();
        check(balance($db, 2) === 100, 'Savepoint rollback did not restore row.');
        $db->beginTransaction();
        $db->table('lab_balances')->where('id', '=', 2)->update(['balance' => 110]);
        $db->afterCommit(static function () use (&$effects): void { $effects[] = 'inner'; });
        $db->commitTransaction();
        check($effects === [], 'Callback executed before durable outer commit.');
    });
    check($effects === ['outer', 'inner'], 'Nested callback promotion/discard failed.');
    check(balance($db, 1) === 90 && balance($db, 2) === 110, 'Transfer reconciliation failed.');
    try {
        $db->transaction(function (Connection $db) use (&$effects): void {
            $db->table('lab_balances')->where('id', '=', 1)->update(['balance' => 0]);
            $db->afterCommit(static function () use (&$effects): void { $effects[] = 'rolled-back'; });
            throw new DomainException('Intentional rollback');
        });
        throw new LogicException('Rollback exception was swallowed.');
    } catch (TransactionException $e) {
        check($e->getPrevious() instanceof DomainException, 'Unexpected rollback failure.');
    }
    check(balance($db, 1) === 90 && $effects === ['outer', 'inner'], 'Rollback leaked data or side effects.');
    check($db->transactionLevel() === 0, 'Transaction state leaked.');
    $sum = $db->readOnlyTransaction(static fn(Connection $db): int => (int) $db->scalar('SELECT SUM(balance) FROM lab_balances'));
    check($sum === 200, 'Read-only transaction result mismatch.');
    echo "Nested transaction, savepoint and callback reconciliation passed\n";
}

function deadlock(Connection $db, int $id): void
{
    $peer = 3 - $id;
    $dir = (string) getenv('EVIDENCE');
    $attempts = 0;
    $effects = 0;
    $db->transaction(function (Connection $db) use ($id, $peer, $dir, &$attempts, &$effects): void {
        ++$attempts;
        $db->table('lab_balances')->where('id', '=', $id)->lockForUpdate()->first();
        $db->afterCommit(static function () use (&$effects): void { ++$effects; });
        $db->update('UPDATE lab_balances SET balance = balance + 1 WHERE id = ?', [$id]);
        if ($attempts === 1) {
            check(file_put_contents($dir . '/locked-' . $id, 'ready', LOCK_EX) !== false, 'Barrier write failed.');
            waitFor(static fn(): bool => is_file($dir . '/locked-' . $peer), 'Deadlock peer never acquired its first lock.');
        }
        $db->table('lab_balances')->where('id', '=', $peer)->lockForUpdate()->first();
        $db->update('UPDATE lab_balances SET balance = balance + 1 WHERE id = ?', [$peer]);
    }, attempts: 3);
    check($effects === 1 && $db->transactionLevel() === 0, 'Retry leaked a callback or transaction.');
    file_put_contents($dir . '/deadlock-' . $id . '.json', json_encode([
        'attempts' => $attempts, 'effects' => $effects,
    ], JSON_THROW_ON_ERROR), LOCK_EX);
}

function verifyDeadlock(Connection $db): void
{
    $dir = (string) getenv('EVIDENCE');
    $first = json_decode((string) file_get_contents($dir . '/deadlock-1.json'), true, flags: JSON_THROW_ON_ERROR);
    $second = json_decode((string) file_get_contents($dir . '/deadlock-2.json'), true, flags: JSON_THROW_ON_ERROR);
    check($first['attempts'] + $second['attempts'] >= 3, 'No actual transaction retry observed.');
    check($first['effects'] === 1 && $second['effects'] === 1, 'Duplicate committed side effects.');
    check(balance($db, 1) === 102 && balance($db, 2) === 102, 'Deadlock retry lost or duplicated writes.');
    echo "Actual database deadlock and retry reconciled\n";
}

function pool(): void
{
    $pool = new Pool(['min_connections' => 0, 'max_connections' => 2]);
    $pool->addConfig('main', config());
    $manager = new PoolManager($pool);
    try {
        $manager->warmUp('main', 2);
        $a = $manager->checkout('main');
        $b = $manager->checkout('main');
        $connection = $a->connection();
        check($connection !== $b->connection(), 'Concurrent leases share one wrapper.');
        try {
            $manager->checkout('main');
            throw new LogicException('Pool allowed over-capacity checkout.');
        } catch (ConnectionException) {
            // Expected capacity rejection.
        }
        $connection->setQueryTimeoutMs(5000);
        $a->release();
        $next = $manager->checkout('main');
        check($next->connection() === $connection, 'Healthy idle wrapper was not reused.');
        check($next->connection()->getQueryTimeoutMs() === null, 'Request timeout leaked into new lease.');
        try {
            $a->connection();
            throw new LogicException('Released lease retained ownership.');
        } catch (ConnectionException | LogicException $e) {
            check($e->getMessage() !== 'Released lease retained ownership.', 'Stale lease access succeeded.');
        }
        $next->connection()->beginTransaction();
        $next->release();
        $replacement = $manager->checkout('main');
        check($replacement->connection() !== $connection, 'Active transaction wrapper was reused.');
        check($replacement->connection()->transactionLevel() === 0, 'Replacement transaction state was dirty.');
        check((int) $replacement->connection()->scalar('SELECT 1') === 1, 'Replacement connection unusable.');
        $replacement->release();
        $b->release();
        echo json_encode($pool->getStats(), JSON_THROW_ON_ERROR), PHP_EOL;
    } finally {
        $pool->closeAll();
    }
}

/** @param list<array<string,int>> $read */
function routed(string $strategy, array $read, bool $sticky = false): Connection
{
    return new Connection(config([
        'read' => $read, 'read_strategy' => $strategy, 'sticky' => $sticky,
        'read_health_cooldown' => 30, 'read_latency_ttl' => 15,
        'read_session_read_only' => true,
    ]));
}

function serverPort(Connection $db): int
{
    return (int) $db->scalar('SELECT inet_server_port()');
}

function replicas(Connection $primary): void
{
    setup($primary);
    $read = [['port' => 5433, 'weight' => 1], ['port' => 5434, 'weight' => 3]];
    foreach ([5433, 5434] as $port) {
        $standby = new Connection(config(['port' => $port]));
        waitFor(static function () use ($standby): bool {
            try {
                return (int) $standby->scalar('SELECT COUNT(*) FROM lab_balances') === 2;
            } catch (ConnectionException) {
                return false;
            }
        }, 'Physical standby did not replay seed data.', 40);
        check(in_array($standby->scalar('SELECT pg_is_in_recovery()'), [true, 't', 1, '1'], true), 'Endpoint is not a physical standby.');
    }
    $round = routed('round_robin', $read);
    $ports = [];
    for ($i = 0; $i < 6; ++$i) {
        if ($i > 0) {
            $round->reconnect(false);
        }
        $ports[] = serverPort($round);
        check(serverPort($round) === $ports[$i], 'Existing read connection did not retain endpoint.');
    }
    check(count(array_unique($ports)) === 2 && $ports[0] === $ports[2] && $ports[1] === $ports[3], 'Round-robin reconnect sequence mismatch.');
    $weighted = routed('weighted', $read);
    $counts = [5433 => 0, 5434 => 0];
    for ($i = 0; $i < 240; ++$i) {
        if ($i > 0) {
            $weighted->reconnect(false);
        }
        $port = serverPort($weighted);
        check(isset($counts[$port]), 'Weighted read unexpectedly reached primary.');
        ++$counts[$port];
    }
    // Expected 75%; broad statistical band prevents incidental random failures.
    check($counts[5434] >= 144 && $counts[5434] <= 216, 'Weighted selection outside 60–90% band.');
    $random = routed('random', $read);
    $seen = [];
    for ($i = 0; $i < 32; ++$i) {
        if ($i > 0) {
            $random->reconnect(false);
        }
        $seen[serverPort($random)] = true;
    }
    check(count($seen) === 2 && isset($seen[5433], $seen[5434]), 'Random selection did not exercise both standbys.');
    $least = routed('least_latency', $read);
    $winner = serverPort($least);
    $least->reconnect(false);
    check(isset($counts[$winner]) && serverPort($least) === $winner, 'Least-latency TTL winner not retained.');
    $sticky = routed('round_robin', $read, true);
    check(isset($counts[serverPort($sticky)]), 'Initial sticky read did not use standby.');
    $sticky->update('UPDATE lab_balances SET balance = 101 WHERE id = 1');
    check(serverPort($sticky) === 5432 && balance($sticky, 1) === 101, 'Read-after-write did not stay on primary.');
    check($sticky->resetRuntimeStateForReuse() && isset($counts[serverPort($sticky)]), 'Sticky state leaked into next execution.');
    $fallback = routed('round_robin', [['port' => 5439, 'weight' => 1]]);
    check(serverPort($fallback) === 5432, 'Unavailable replicas did not fall back to primary.');
    echo json_encode(['round_robin' => $ports, 'weighted' => $counts, 'least_latency' => $winner], JSON_THROW_ON_ERROR), PHP_EOL;
}

require __DIR__ . '/database-data.php';
require __DIR__ . '/database-operations.php';

$db = new Connection(config());
match ($argv[1] ?? '') {
    'lock-wait' => lockWait($db),
    'pool-lifecycle' => poolLifecycle($db),
    'budgets' => budgets($db),
    'isolation' => isolation($db),
    'wal' => wal($db),
    'replica-lag' => replicaLag($db),
    'replica-partial' => replicaPartial(),
    'replica-recover' => replicaRecover(),
    'schema' => schemaWorkload($db),
    'query' => queryWorkload($db),
    'repository' => repositoryWorkload($db),
    'query-cache' => cacheWorkload($db),
    'stream' => streamWorkload($db),
    'security' => securityWorkload($db),
    'transactions' => transactions($db),
    'setup-locking' => setup($db),
    'deadlock' => deadlock($db, (int) ($argv[2] ?? 0)),
    'verify-deadlock' => verifyDeadlock($db),
    'pool' => pool(),
    'replicas' => replicas($db),
    default => throw new InvalidArgumentException('Unknown database workload.'),
};
