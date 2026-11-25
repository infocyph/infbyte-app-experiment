<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\TransactionException;

function configureRaceCache(Connection $db): void
{
    $db->setQueryCache(Cache::redis('lab-db-query-race', 'redis://127.0.0.1:6379', options: new CacheOptions(failOpen: false)));
}

function cachedRaceBalance(Connection $db): int
{
    $rows = $db->table('lab_balances')->where('id', '=', 1)->cacheFor(120)->get();
    check(count($rows) === 1, 'Cached result did not contain exactly one row.');

    return (int) $rows[0]['balance'];
}

function databaseCacheRaceRead(Connection $db, int $generation, int $worker): void
{
    check(in_array($generation, [1, 2], true) && $worker >= 0 && $worker < 12, 'Invalid race arguments.');
    configureRaceCache($db);
    $root = (string) getenv('EVIDENCE');
    $db->resetStats();
    check(touch($root . "/ready-$generation-$worker"), 'Worker readiness write failed.');
    waitFor(static fn(): bool => is_file($root . "/go-$generation"), 'Race release missing.', 45);

    $expected = $generation === 1 ? 100 : 125;
    check(cachedRaceBalance($db) === $expected, 'Cold result did not match durable generation.');
    $cold = $db->getStats()['queries'];
    check(cachedRaceBalance($db) === $expected, 'Warm result did not match durable generation.');
    $warm = $db->getStats()['queries'] - $cold;
    check($warm === 0, 'Warm read executed a SQL query.');
    echo json_encode(['cold' => $cold, 'warm' => $warm, 'balance' => $expected], JSON_THROW_ON_ERROR), PHP_EOL;
}

function databaseCacheRaceVerify(int $generation): void
{
    check(in_array($generation, [1, 2], true), 'Invalid race generation.');
    $root = (string) getenv('EVIDENCE');
    $cold = 0;
    for ($worker = 0; $worker < 12; ++$worker) {
        $result = json_decode((string) file_get_contents($root . "/race-$generation-$worker.json"), true, flags: JSON_THROW_ON_ERROR);
        check(in_array($result['cold'], [0, 1], true) && $result['warm'] === 0, 'Unexpected per-worker SQL count.');
        check($result['balance'] === ($generation === 1 ? 100 : 125), 'Worker result diverged.');
        $cold += $result['cold'];
    }
    check($cold === 1, 'Shared cold query executed other than exactly once.');
    echo "Generation $generation: 12 workers, 24 reads, exactly one SQL execution\n";
}

function databaseCacheRaceAdvance(Connection $db): void
{
    configureRaceCache($db);
    $db->resetStats();
    check(cachedRaceBalance($db) === 100 && $db->getStats()['queries'] === 0, 'Fresh worker did not hit shared cache.');
    try {
        $db->transaction(static function (Connection $transaction): void {
            $transaction->table('lab_balances')->where('id', '=', 1)->update(['balance' => 999]);
            check(cachedRaceBalance($transaction) === 999, 'Transaction did not bypass shared cache.');
            throw new DomainException('Intentional rollback');
        });
        throw new LogicException('Transaction did not roll back.');
    } catch (TransactionException $e) {
        check($e->getPrevious() instanceof DomainException, 'Unexpected rollback failure.');
    }
    $db->resetStats();
    check(cachedRaceBalance($db) === 100 && $db->getStats()['queries'] === 0, 'Rollback invalidated shared cache.');
    $db->transaction(static function (Connection $transaction): void {
        $transaction->table('lab_balances')->where('id', '=', 1)->update(['balance' => 125]);
        $transaction->table('lab_balances')->where('id', '=', 2)->update(['balance' => 75]);
    });
    check(balance($db, 1) === 125 && balance($db, 2) === 75, 'Committed transfer failed reconciliation.');
}

function databaseCacheOverlapHold(Connection $db): void
{
    $pdo = $db->getPdo();
    $postgres = getenv('DB_BACKEND') === 'pgsql';
    if ($postgres) {
        $pdo->query('SELECT pg_advisory_lock(712921)')->closeCursor();
    } else {
        check((int) $pdo->query("SELECT GET_LOCK('lab-cache-overlap-100', 5)")->fetchColumn() === 1, 'Named lock acquisition failed.');
    }
    try {
        check(touch(getenv('EVIDENCE') . '/overlap-held'), 'Gate marker failed.');
        waitFor(static fn(): bool => is_file(getenv('EVIDENCE') . '/overlap-release'), 'Gate release missing.', 45);
    } finally {
        if ($postgres) {
            $pdo->query('SELECT pg_advisory_unlock(712921)')->closeCursor();
        } else {
            check((int) $pdo->query("SELECT RELEASE_LOCK('lab-cache-overlap-100')")->fetchColumn() === 1, 'Named lock release failed.');
        }
    }
}

function databaseCacheOverlapRead(Connection $db, bool $verify): void
{
    configureRaceCache($db);
    $postgres = getenv('DB_BACKEND') === 'pgsql';
    // Depend on the actual row value, so the old result has been read before blocking.
    $gate = $postgres
        ? 'pg_advisory_xact_lock(712821 + balance) AS gate'
        : "GET_LOCK(CONCAT('lab-cache-overlap-', balance), 45) AS gate";
    $query = $db->table('lab_balances')->select('balance')
        ->selectRaw($gate)
        ->where('id', '=', 1)->cacheTags('overlap-test')->cacheFor(120);
    $db->resetStats();
    $rows = $query->get();
    check(count($rows) === 1, 'Overlap result row count differs.');
    if (!$postgres) {
        check((int) $rows[0]['gate'] === 1, 'Reader did not acquire the named lock.');
    }
    $value = (int) $rows[0]['balance'];
    if (!$verify) {
        check($value === 100 && $db->getStats()['queries'] >= 1, 'In-flight reader did not return the old SQL snapshot.');
        echo "In-flight snapshot: $value\n";
        return;
    }
    check($value === 125, 'Old in-flight snapshot polluted post-commit cache generation.');
    $cold = $db->getStats()['queries'];
    check((int) $query->get()[0]['balance'] === 125 && $db->getStats()['queries'] === $cold, 'Fresh generation did not provide warm cache hit.');
    echo "Fresh generation returned committed balance and warm cache hit\n";
}

function databaseCacheOverlapCommit(Connection $db): void
{
    configureRaceCache($db);
    $postgres = getenv('DB_BACKEND') === 'pgsql';
    waitFor(static fn(): bool => (int) ($postgres
        ? $db->scalar(
            "SELECT COUNT(*) FROM pg_stat_activity WHERE wait_event_type = 'Lock' AND wait_event = 'advisory' AND query LIKE ? AND pid <> pg_backend_pid()",
            ['%pg_advisory_xact_lock(712821 + balance)%'],
        )
        : $db->scalar(
            "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND LOWER(STATE) = 'user lock' AND INFO LIKE ?",
            ["%GET_LOCK(CONCAT('lab-cache-overlap-', balance)%"],
        )) === 1, 'No actual in-flight SQL lock wait observed.', 25);
    $db->transaction(static function (Connection $transaction): void {
        $transaction->table('lab_balances')->where('id', '=', 1)->update(['balance' => 125]);
        $transaction->table('lab_balances')->where('id', '=', 2)->update(['balance' => 75]);
    });
    check(balance($db, 1) === 125 && balance($db, 2) === 75, 'Overlapping committed transfer did not reconcile.');
    check(touch(getenv('EVIDENCE') . '/overlap-release'), 'Commit release marker failed.');
}
