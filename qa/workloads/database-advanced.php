<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\DBLayer\Connection\Connection;

function serializationRetry(Connection $db, int $id): void
{
    check(in_array($id, [1, 2], true), 'Invalid serialization worker.');
    $db->getPdo()->exec('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    $dir = (string) getenv('EVIDENCE');
    $attempts = 0;
    $effects = 0;
    $db->transaction(static function (Connection $db) use ($id, $dir, &$attempts, &$effects): void {
        ++$attempts;
        $value = balance($db, 1);
        $db->afterCommit(static function () use (&$effects): void { ++$effects; });
        if ($attempts === 1) {
            check(file_put_contents($dir . '/snapshot-' . $id, 'ready', LOCK_EX) !== false, 'Snapshot barrier failed.');
            waitFor(static fn(): bool => is_file($dir . '/snapshot-' . (3 - $id)), 'Peer snapshot missing.');
        }
        $db->table('lab_balances')->where('id', '=', 1)->update(['balance' => $value + 1]);
    }, attempts: 3);
    check($effects === 1 && $db->transactionLevel() === 0, 'Serialization retry leaked state.');
    check(file_put_contents($dir . '/retry-' . $id . '.json', json_encode(['attempts' => $attempts, 'effects' => $effects], JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'Retry evidence failed.');
}

function verifySerialization(Connection $db): void
{
    $attempts = 0;
    foreach ([1, 2] as $id) {
        $record = json_decode((string) file_get_contents(getenv('EVIDENCE') . '/retry-' . $id . '.json'), true, flags: JSON_THROW_ON_ERROR);
        $attempts += $record['attempts'];
        check($record['effects'] === 1, 'Duplicate serialization side effect.');
    }
    check($attempts >= 3 && balance($db, 1) === 102, 'Serialization conflict did not produce a retry with exact reconciliation.');
    echo "Actual SERIALIZABLE conflict, retry and commit callbacks reconciled\n";
}

function lockModes(Connection $db): void
{
    setup($db);
    $peer = new Connection(config());
    $db->beginTransaction();
    try {
        $db->table('lab_balances')->where('id', '=', 1)->lockForUpdate()->first();
        $rows = $peer->transaction(static fn(Connection $conn): array => $conn->select('SELECT id FROM lab_balances ORDER BY id FOR UPDATE SKIP LOCKED'));
        check(array_map('intval', array_column($rows, 'id')) === [2], 'SKIP LOCKED did not exclude reserved row.');
        rejected(static fn() => $peer->transaction(static fn(Connection $conn): array => $conn->select('SELECT id FROM lab_balances WHERE id = 1 FOR UPDATE NOWAIT')), 'NOWAIT accepted held row.');
        check($peer->transactionLevel() === 0, 'NOWAIT leaked transaction.');
    } finally {
        $db->rollbackTransaction();
    }
    check(count($peer->transaction(static fn(Connection $conn): array => $conn->select('SELECT id FROM lab_balances ORDER BY id FOR UPDATE NOWAIT'))) === 2, 'Released rows remained locked.');
    if ($db->getDriverName() === 'pgsql') {
        $lock = 'SELECT pg_try_advisory_lock(87291)';
        $unlock = 'SELECT pg_advisory_unlock(87291)';
    } else {
        $lock = "SELECT GET_LOCK('lab-advisory-87291', 0)";
        $unlock = "SELECT RELEASE_LOCK('lab-advisory-87291')";
    }
    $acquired = static fn(mixed $value): bool => in_array($value, [true, 1, '1', 't'], true);
    check($acquired($db->scalar($lock)), 'Advisory lock acquisition failed.');
    try {
        check(!$acquired($peer->scalar($lock)), 'Peer acquired same advisory lock.');
    } finally {
        check($acquired($db->scalar($unlock)), 'Advisory unlock failed.');
    }
    check($acquired($peer->scalar($lock)) && $acquired($peer->scalar($unlock)), 'Advisory lock not reusable.');
    echo "Real NOWAIT, SKIP LOCKED and session advisory lock ownership passed\n";
}

function nativeOwner(Connection $db): void
{
    setup($db);
    $cache = Cache::file('native-owner', getenv('EVIDENCE') . '/native-cache');
    $db->setQueryCache($cache);
    $peer = new Connection(config());
    $peer->setQueryCache($cache);
    $read = static fn(Connection $conn): int => (int) $conn->table('lab_balances')->where('id', '=', 1)->cacheFor(120)->value('balance');
    check($read($db) === 100, 'Cache initialization failed.');
    $pdo = $db->getPdo();
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('UPDATE lab_balances SET balance = ? WHERE id = 1');
        $statement->execute([105]);
        $statement->closeCursor();
        check($read($db) === 105, 'Externally owned transaction read stale cache.');
        rejected(static fn() => $db->afterCommit(static fn() => throw new LogicException('Unexpected native callback.')), 'Native owner accepted DBLayer afterCommit.');
        rejected(static fn() => $db->table('lab_balances')->where('id', '=', 1)->update(['balance' => 999]), 'Cache-aware native-owner write accepted.');
        check($pdo->inTransaction(), 'DBLayer ended externally owned transaction.');
    } finally {
        $pdo->rollBack();
    }
    check($read($peer) === 100 && balance($db, 1) === 100, 'External rollback/cache state diverged.');
    echo "External PDO ownership, cache bypass and unsafe callback/write rejection passed\n";
}

function callbackFailure(Connection $db): void
{
    setup($db);
    $effects = [];
    $failure = new DomainException('first post-commit failure');
    try {
        $db->transaction(static function (Connection $conn) use (&$effects, $failure): void {
            $conn->table('lab_balances')->where('id', '=', 1)->update(['balance' => 101]);
            $conn->afterCommit(static function () use (&$effects, $failure): never {
                $effects[] = 'first';
                throw $failure;
            });
            $conn->afterCommit(static function () use (&$effects): void { $effects[] = 'second'; });
        }, attempts: 3);
        throw new LogicException('Post-commit error was swallowed.');
    } catch (Throwable $caught) {
        check($caught === $failure || $caught->getPrevious() === $failure, 'First callback error was not propagated.');
    }
    check($effects === ['first', 'second'], 'Callback failure suppressed subsequent callback or replayed committed callbacks.');
    check(balance($db, 1) === 101 && $db->transactionLevel() === 0, 'Durable commit was rolled back or transaction leaked.');
    $db->afterCommit(static function () use (&$effects): void { $effects[] = 'outside'; });
    check($effects === ['first', 'second', 'outside'], 'Outside-transaction callback was not immediate.');
    echo "Post-commit exception propagation, remaining callback and durable write passed\n";
}
