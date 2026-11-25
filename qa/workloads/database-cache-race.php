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

function databaseCacheOverlapSeed(Connection $db): void
{
    $driver = (string) getenv('DB_BACKEND');
    if ($driver === 'sqlite') {
        check(strtolower((string) $db->getPdo()->query('PRAGMA journal_mode = WAL')->fetchColumn()) === 'wal', 'WAL not active.');
    } elseif ($driver === 'mssql') {
        $master = new Connection(config(['database' => 'master']));
        $master->getPdo()->exec('ALTER DATABASE experiment SET READ_COMMITTED_SNAPSHOT ON');
        check((int) $db->scalar("SELECT is_read_committed_snapshot_on FROM sys.databases WHERE name = 'experiment'") === 1, 'Read-committed snapshot isolation not active.');
        $db->statement('CREATE TABLE lab_overlap_gate (id INTEGER PRIMARY KEY, token INTEGER NOT NULL)');
        $db->statement('INSERT INTO lab_overlap_gate (id, token) VALUES (100, 1), (125, 1)');
    }
    setup($db);
}

function databaseCacheOverlapHold(Connection $db): void
{
    $pdo = $db->getPdo();
    $driver = (string) getenv('DB_BACKEND');
    try {
        if ($driver === 'pgsql') {
            $pdo->query('SELECT pg_advisory_lock(712921)')->closeCursor();
        } elseif ($driver === 'mssql') {
            $pdo->beginTransaction();
            check($pdo->exec('UPDATE lab_overlap_gate WITH (ROWLOCK) SET token = 2 WHERE id = 100') === 1, 'SQL Server gate row not locked.');
        } else {
            check((int) $pdo->query("SELECT GET_LOCK('lab-cache-overlap-100', 5)")->fetchColumn() === 1, 'Named lock acquisition failed.');
        }
        check(touch(getenv('EVIDENCE') . '/overlap-held'), 'Gate marker failed.');
        waitFor(static fn(): bool => is_file(getenv('EVIDENCE') . '/overlap-release'), 'Gate release missing.', 45);
    } finally {
        if ($driver === 'pgsql') {
            $pdo->query('SELECT pg_advisory_unlock(712921)')->closeCursor();
        } elseif ($driver === 'mssql') {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } else {
            check((int) $pdo->query("SELECT RELEASE_LOCK('lab-cache-overlap-100')")->fetchColumn() === 1, 'Named lock release failed.');
        }
    }
}

function databaseCacheOverlapRead(Connection $db, bool $verify): void
{
    configureRaceCache($db);
    $driver = (string) getenv('DB_BACKEND');
    if ($driver === 'sqlite') {
        $root = (string) getenv('EVIDENCE');
        $pdo = $db->getPdo();
        check($pdo->sqliteCreateFunction('lab_overlap_gate', static function (int $value) use ($verify, $root): int {
            if (!$verify) {
                check($value === 100, 'SQLite reader acquired the wrong snapshot.');
                check(touch($root . '/overlap-reader-blocked'), 'SQLite SQL callback marker failed.');
                waitFor(static fn(): bool => is_file($root . '/overlap-release'), 'SQLite reader release missing.', 45);
            }
            return 1;
        }, 1), 'SQLite SQL gate registration failed.');
    }
    // Depend on the actual row value, so the old result has been read before blocking.
    $gate = match ($driver) {
        'pgsql' => 'pg_advisory_xact_lock(712821 + balance) AS gate',
        'sqlite' => 'lab_overlap_gate(balance) AS gate',
        'mssql' => '(SELECT token FROM lab_overlap_gate WITH (READCOMMITTEDLOCK, ROWLOCK) WHERE id = lab_balances.balance) AS gate',
        default => "GET_LOCK(CONCAT('lab-cache-overlap-', balance), 45) AS gate",
    };
    $query = $db->table('lab_balances')->select('balance')
        ->selectRaw($gate)
        ->where('id', '=', 1)->cacheTags('overlap-test')->cacheFor(120);
    $db->resetStats();
    $rows = $query->get();
    check(count($rows) === 1, 'Overlap result row count differs.');
    if ($driver !== 'pgsql') {
        check((int) $rows[0]['gate'] === 1, 'Reader did not complete the SQL gate.');
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
    $driver = (string) getenv('DB_BACKEND');
    if ($driver === 'sqlite') {
        waitFor(static fn(): bool => is_file(getenv('EVIDENCE') . '/overlap-reader-blocked'), 'SQLite reader never reached its SQL callback.', 25);
    } else {
        waitFor(static fn(): bool => (int) match ($driver) {
            'pgsql' => $db->scalar(
                "SELECT COUNT(*) FROM pg_stat_activity WHERE wait_event_type = 'Lock' AND wait_event = 'advisory' AND query LIKE ? AND pid <> pg_backend_pid()",
                ['%pg_advisory_xact_lock(712821 + balance)%'],
            ),
            'mssql' => $db->scalar(
                "SELECT COUNT(*) FROM sys.dm_exec_requests r CROSS APPLY sys.dm_exec_sql_text(r.sql_handle) t WHERE r.session_id <> @@SPID AND r.database_id = DB_ID() AND r.blocking_session_id > 0 AND r.wait_type LIKE 'LCK_M_%' AND t.text LIKE ?",
                ['%lab_overlap_gate%'],
            ),
            default => $db->scalar(
                "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND LOWER(STATE) = 'user lock' AND INFO LIKE ?",
                ["%GET_LOCK(CONCAT('lab-cache-overlap-', balance)%"],
            ),
        } === 1, 'No actual in-flight SQL lock wait observed.', 25);
    }
    $db->transaction(static function (Connection $transaction): void {
        $transaction->table('lab_balances')->where('id', '=', 1)->update(['balance' => 125]);
        $transaction->table('lab_balances')->where('id', '=', 2)->update(['balance' => 75]);
    });
    check(balance($db, 1) === 125 && balance($db, 2) === 75, 'Overlapping committed transfer did not reconcile.');
    check(touch(getenv('EVIDENCE') . '/overlap-release'), 'Commit release marker failed.');
}
