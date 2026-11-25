<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;

require getenv('PROJECT') . '/vendor/autoload.php';

function requireState(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function member(int $node, bool $minority): Connection
{
    requireState(in_array($node, [1, 2, 3], true), 'Unknown cluster member.');
    $db = new Connection(ConnectionConfig::fromArray([
        'driver' => (string) getenv('DB_BACKEND'),
        'host' => '127.0.0.1', 'port' => 23305 + $node,
        'database' => 'experiment', 'username' => 'experiment', 'password' => 'experiment',
    ]));
    if (getenv('DB_BACKEND') === 'mysql') {
        $db->getPdo()->exec($minority
            ? "SET SESSION group_replication_consistency = 'EVENTUAL'"
            : "SET SESSION group_replication_consistency = 'BEFORE_AND_AFTER'");
    } elseif (!$minority) {
        $db->getPdo()->exec('SET SESSION wsrep_sync_wait = 1');
    }
    return $db;
}

function expectedLedger(int $count): array
{
    $expected = [];
    for ($id = 1; $id <= $count; ++$id) {
        $expected[] = ['id' => $id, 'debit' => -$id, 'credit' => $id, 'checksum' => hash('sha256', "ledger:$id")];
    }
    return $expected;
}

function verifyLedger(Connection $db, int $count): void
{
    $rows = $db->table('lab_cluster_ledger')->orderBy('id')->get();
    $actual = array_map(static fn(array $row): array => [
        'id' => (int) $row['id'], 'debit' => (int) $row['debit'],
        'credit' => (int) $row['credit'], 'checksum' => $row['checksum'],
    ], $rows);
    requireState($actual === expectedLedger($count), 'Cluster ledger lost, duplicated or corrupted a confirmed transaction.');
}

function appendLedger(Connection $db, int $from, int $to): void
{
    foreach (array_slice(expectedLedger($to), $from - 1) as $row) {
        $effects = 0;
        $db->transaction(static function (Connection $active) use ($row, &$effects): void {
            requireState($active->table('lab_cluster_ledger')->insert($row), 'Ledger insertion failed.');
            $active->afterCommit(static function () use (&$effects): void { ++$effects; });
        });
        requireState($effects === 1 && $db->transactionLevel() === 0, 'Commit did not settle once.');
    }
}

function rejectWrite(Connection $db, array $expectedCodes): void
{
    try {
        $db->table('lab_cluster_ledger')->insert(['id' => 999, 'debit' => -999, 'credit' => 999, 'checksum' => 'forbidden']);
    } catch (Throwable $failure) {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException && in_array((int) ($cause->errorInfo[1] ?? 0), $expectedCodes, true)) {
                echo "Expected native write rejection: " . (int) $cause->errorInfo[1] . PHP_EOL;
                return;
            }
        }
        throw $failure;
    }
    throw new LogicException('Non-writable member accepted a new ledger record.');
}

$phase = $argv[1] ?? '';
$node = (int) ($argv[2] ?? 0);
$db = member($node, $phase === 'minority');
switch ($phase) {
    case 'seed':
        $db->statement('CREATE TABLE lab_cluster_ledger (id INTEGER PRIMARY KEY, debit INTEGER NOT NULL, credit INTEGER NOT NULL, checksum VARCHAR(64) NOT NULL) ENGINE=InnoDB');
        appendLedger($db, 1, 24);
        verifyLedger($db, 24);
        break;
    case 'append':
        verifyLedger($db, 24);
        appendLedger($db, 25, 48);
        verifyLedger($db, 48);
        break;
    case 'verify':
        verifyLedger($db, (int) ($argv[3] ?? 48));
        break;
    case 'secondary':
        requireState((int) $db->scalar('SELECT @@super_read_only') === 1, 'Secondary not fenced.');
        rejectWrite($db, [1290]);
        verifyLedger($db, (int) ($argv[3] ?? 48));
        break;
    case 'minority':
        if (getenv('DB_BACKEND') === 'mysql') {
            requireState((int) $db->scalar('SELECT @@super_read_only') === 1, 'Minority not fenced.');
            rejectWrite($db, [1290, 3101]);
            verifyLedger($db, 24);
        } else {
            rejectWrite($db, [1047]);
        }
        break;
    default:
        throw new InvalidArgumentException('Unknown cluster phase.');
}
echo "$phase/member-$node passed\n";
