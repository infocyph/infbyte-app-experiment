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
