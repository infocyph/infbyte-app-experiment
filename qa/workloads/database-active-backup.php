<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;

function activeBackupRows(Connection $db, int $first, int $last): void
{
    for ($id = $first; $id <= $last; ++$id) {
        $db->transaction(static function (Connection $active) use ($id): void {
            $active->table('lab_backup_orders')->insert(['id' => $id, 'amount' => $id * 3]);
            $active->table('lab_backup_ledger')->insert(['id' => $id, 'debit' => $id * 3, 'credit' => $id * 3]);
        });
    }
}

function activeBackupSeed(Connection $db): void
{
    $db->statement('CREATE TABLE lab_backup_gate (id INTEGER PRIMARY KEY)');
    $db->statement('CREATE TABLE lab_backup_orders (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)');
    $db->statement('CREATE TABLE lab_backup_ledger (id INTEGER PRIMARY KEY REFERENCES lab_backup_orders(id), debit INTEGER NOT NULL, credit INTEGER NOT NULL)');
    activeBackupRows($db, 1, 100);
}

function activeBackupGate(Connection $db): void
{
    $root = (string) getenv('LAB_BACKUP_ROOT');
    $pdo = $db->getPdo();
    $pdo->beginTransaction();
    try {
        $pdo->exec('LOCK TABLE lab_backup_gate IN ACCESS EXCLUSIVE MODE');
        check(file_put_contents($root . '/gate', 'locked', LOCK_EX) !== false, 'Backup gate marker failed.');
        waitFor(static fn(): bool => is_file($root . '/gate-release'), 'Backup gate release missing.', 100);
        $pdo->commit();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

function activeBackupDigest(PDO $pdo): array
{
    $result = [];
    foreach (['lab_backup_orders', 'lab_backup_ledger'] as $table) {
        $rows = $pdo->query("SELECT row_to_json(t)::text FROM $table t ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $result[$table] = ['count' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
    }
    return $result;
}

function activeBackupSnapshot(Connection $db): void
{
    $root = (string) getenv('LAB_BACKUP_ROOT');
    $pdo = $db->getPdo();
    $pdo->beginTransaction();
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $snapshot = $pdo->query('SELECT pg_export_snapshot()')->fetchColumn();
        check(is_string($snapshot) && $snapshot !== '', 'Exported snapshot unavailable.');
        $digest = activeBackupDigest($pdo);
        check($digest['lab_backup_orders']['count'] === 100 && $digest['lab_backup_ledger']['count'] === 100, 'Unexpected initial snapshot.');
        check(file_put_contents($root . '/expected.json', json_encode($digest, JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'Snapshot digest write failed.');
        check(file_put_contents($root . '/snapshot', $snapshot, LOCK_EX) !== false, 'Snapshot marker write failed.');
        waitFor(static fn(): bool => is_file($root . '/snapshot-release'), 'Snapshot release missing.', 100);
        check(activeBackupDigest($pdo) === $digest, 'Exporting snapshot changed during concurrent commits.');
        $pdo->commit();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

function activeBackupWait(Connection $db): void
{
    waitFor(static fn(): bool => (int) $db->scalar(
        "SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = 'lab-active-backup' AND wait_event_type = 'Lock'",
    ) === 1, 'pg_dump never reached an actual blocked relation lock.', 25);
    echo "Observed real pg_dump lock wait while exported snapshot remained active\n";
}

function activeBackupWrite(Connection $db, int $worker): void
{
    check($worker >= 0 && $worker < 8, 'Invalid backup writer.');
    activeBackupRows($db, 101 + $worker * 20, 120 + $worker * 20);
    check($db->transactionLevel() === 0, 'Backup writer retained transaction state.');
    echo "Worker $worker committed twenty paired operations during backup\n";
}

function activeBackupVerify(Connection $live): void
{
    $expected = json_decode((string) file_get_contents(getenv('LAB_BACKUP_ROOT') . '/expected.json'), true, flags: JSON_THROW_ON_ERROR);
    $restored = new Connection(config(['database' => 'experiment_active_backup']));
    $actual = activeBackupDigest($restored->getPdo());
    check($actual === $expected, 'Restored database diverged from exported snapshot.');
    $current = activeBackupDigest($live->getPdo());
    check($current['lab_backup_orders']['count'] === 260 && $current['lab_backup_ledger']['count'] === 260 && $current !== $actual, 'Concurrent commits did not survive in live database.');
    foreach ([[$live, 260], [$restored, 100]] as [$db, $count]) {
        check((int) $db->scalar('SELECT COUNT(*) FROM lab_backup_orders o JOIN lab_backup_ledger l ON l.id = o.id WHERE l.debit = o.amount AND l.credit = o.amount') === $count, 'Order and ledger snapshot consistency failed.');
        check((int) $db->scalar('SELECT SUM(debit-credit) FROM lab_backup_ledger') === 0, 'Ledger no longer balances.');
    }
    echo json_encode(['restored' => $actual, 'live' => $current], JSON_THROW_ON_ERROR), PHP_EOL;
}

function physicalBackupSeed(Connection $db): void
{
    activeBackupSeed($db);
    $db->statement('CREATE TABLE lab_backup_padding (id INTEGER PRIMARY KEY, payload TEXT NOT NULL)');
    $db->statement('ALTER TABLE lab_backup_padding ALTER COLUMN payload SET STORAGE EXTERNAL');
    $db->statement("INSERT INTO lab_backup_padding SELECT i, repeat(md5(i::text), 256) FROM generate_series(1, 8192) AS i");
    check((int) $db->scalar("SELECT pg_total_relation_size('lab_backup_padding')") >= 64 * 1024 * 1024, 'Physical backup fixture is too small for controlled overlap.');
}

function physicalBackupWait(Connection $db): void
{
    waitFor(static fn(): bool => (int) $db->scalar(
        "SELECT COUNT(*) FROM pg_stat_progress_basebackup WHERE phase = 'streaming database files'",
    ) === 1, 'pg_basebackup did not enter physical file streaming.', 40);
}

function physicalBackupExpected(Connection $db): void
{
    $digest = activeBackupDigest($db->getPdo());
    check($digest['lab_backup_orders']['count'] === 260 && $digest['lab_backup_ledger']['count'] === 260, 'Physical backup writers did not reconcile.');
    check((int) $db->scalar('SELECT COUNT(*) FROM pg_stat_progress_basebackup') === 1, 'Physical backup finished before concurrent commits were confirmed.');
    check(file_put_contents(getenv('LAB_BACKUP_ROOT') . '/physical-expected.json', json_encode($digest, JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'Physical backup digest evidence unavailable.');
}

function physicalBackupVerify(): void
{
    $restored = new Connection(config(['port' => 5435, 'timeout' => 5]));
    $expected = json_decode((string) file_get_contents(getenv('LAB_BACKUP_ROOT') . '/physical-expected.json'), true, flags: JSON_THROW_ON_ERROR);
    check(activeBackupDigest($restored->getPdo()) === $expected, 'Standalone physical recovery did not include all confirmed commits.');
    check(!(bool) $restored->scalar('SELECT pg_is_in_recovery()'), 'Restored instance remained a standby.');
    check((int) $restored->scalar('SELECT COUNT(*) FROM lab_backup_padding') === 8192, 'Physical padding relation lost rows.');
    check((int) $restored->scalar('SELECT COUNT(*) FROM lab_backup_orders o JOIN lab_backup_ledger l ON l.id = o.id WHERE o.amount = l.debit AND o.amount = l.credit') === 260, 'Recovered ledger does not match order effects.');
    activeBackupRows($restored, 261, 261);
    check((int) $restored->scalar('SELECT COUNT(*) FROM lab_backup_ledger') === 261, 'Standalone recovered database rejected new writes.');
    echo json_encode(['recovered' => $expected, 'post_recovery_write' => true], JSON_THROW_ON_ERROR), PHP_EOL;
}

function portableBackupDigest(Connection $db): array
{
    $result = [];
    foreach (['lab_backup_orders' => ['id', 'amount'], 'lab_backup_ledger' => ['id', 'debit', 'credit']] as $table => $columns) {
        $rows = $db->select('SELECT ' . implode(', ', $columns) . ' FROM ' . $table . ' ORDER BY id');
        $canonical = array_map(static fn(array $row): array => array_map('intval', array_values($row)), $rows);
        $result[$table] = ['count' => count($canonical), 'sha256' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR))];
    }
    return $result;
}

function portableBackupSeed(Connection $db): void
{
    if ($db->getDriverName() === 'sqlite') {
        check(strtolower((string) $db->scalar('PRAGMA journal_mode = WAL')) === 'wal', 'SQLite WAL mode unavailable.');
    }
    activeBackupSeed($db);
    $digest = portableBackupDigest($db);
    check(file_put_contents(getenv('LAB_BACKUP_ROOT') . '/expected.json', json_encode($digest, JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'Initial backup digest unavailable.');
}

function portableBackupWriter(Connection $db): void
{
    $root = (string) getenv('LAB_BACKUP_ROOT');
    $effects = 0;
    $db->transaction(static function (Connection $active) use ($root, &$effects): void {
        $active->table('lab_backup_orders')->insert(['id' => 101, 'amount' => 303]);
        $active->table('lab_backup_ledger')->insert(['id' => 101, 'debit' => 303, 'credit' => 303]);
        $active->afterCommit(static function () use (&$effects): void { ++$effects; });
        check(file_put_contents($root . '/writer-held', 'uncommitted', LOCK_EX) !== false, 'Writer marker failed.');
        waitFor(static fn(): bool => is_file($root . '/writer-release'), 'Backup did not release writer.', 120);
        check($effects === 0, 'Commit callback ran before commit.');
    });
    check($effects === 1 && $db->transactionLevel() === 0, 'Writer terminal state diverged.');
}

function portableBackupSqlite(): void
{
    $root = (string) getenv('LAB_BACKUP_ROOT');
    $source = new SQLite3(getenv('EVIDENCE') . '/db.sqlite', SQLITE3_OPEN_READONLY);
    $destination = new SQLite3($root . '/restored.sqlite');
    try {
        $source->busyTimeout(5000);
        check($source->backup($destination), 'Native SQLite online backup failed.');
        check($destination->querySingle('PRAGMA integrity_check') === 'ok', 'SQLite backup integrity failed.');
    } finally {
        $destination->close();
        $source->close();
    }
}

function portableBackupSqlServer(): void
{
    $master = new Connection(config(['database' => 'master']));
    $pdo = $master->getPdo();
    $pdo->exec("BACKUP DATABASE experiment TO DISK='/var/opt/mssql/data/lab_active.bak' WITH COPY_ONLY, INIT, CHECKSUM");
    $pdo->exec("RESTORE VERIFYONLY FROM DISK='/var/opt/mssql/data/lab_active.bak' WITH CHECKSUM");
    $files = $pdo->query("SELECT name, type FROM sys.master_files WHERE database_id = DB_ID('experiment')")->fetchAll(PDO::FETCH_ASSOC);
    check(count($files) === 2, 'Unexpected SQL Server database file topology.');
    $moves = [];
    foreach ($files as $file) {
        $name = $pdo->quote((string) $file['name']);
        $target = (int) $file['type'] === 0 ? 'lab_restored.mdf' : 'lab_restored.ldf';
        $moves[] = "MOVE $name TO '/var/opt/mssql/data/$target'";
    }
    $pdo->exec("RESTORE DATABASE experiment_active_backup FROM DISK='/var/opt/mssql/data/lab_active.bak' WITH RECOVERY, CHECKSUM, " . implode(', ', $moves));
}

function portableBackupVerify(Connection $live): void
{
    $root = (string) getenv('LAB_BACKUP_ROOT');
    $expected = json_decode((string) file_get_contents($root . '/expected.json'), true, flags: JSON_THROW_ON_ERROR);
    $database = $live->getDriverName() === 'sqlite' ? $root . '/restored.sqlite' : 'experiment_active_backup';
    $restored = new Connection(config(['database' => $database]));
    $snapshot = portableBackupDigest($restored);
    $current = portableBackupDigest($live);
    check($snapshot === $expected, 'Restored backup included an uncommitted or incomplete effect.');
    check($current['lab_backup_orders']['count'] === 101 && $current['lab_backup_ledger']['count'] === 101, 'Live committed writer was lost.');
    check((int) $live->scalar('SELECT COUNT(*) FROM lab_backup_orders o JOIN lab_backup_ledger l ON o.id = l.id WHERE o.amount = l.debit AND o.amount = l.credit') === 101, 'Live paired ledger diverged.');
    activeBackupRows($restored, 102, 102);
    check((int) $restored->scalar('SELECT COUNT(*) FROM lab_backup_ledger') === 101 && (int) $live->scalar('SELECT COUNT(*) FROM lab_backup_orders WHERE id = 102') === 0, 'Restored writes crossed source boundary.');
    echo json_encode(['snapshot' => $snapshot, 'live' => $current, 'independent_restore_write' => true], JSON_THROW_ON_ERROR), PHP_EOL;
}
