<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\QueryException;

require getenv('PROJECT') . '/vendor/autoload.php';

function haCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function haConnection(): Connection
{
    return new Connection(ConnectionConfig::fromArray([
        'driver' => 'mssql',
        'host' => (string) getenv('LAB_HA_LISTENER'),
        'port' => 1433,
        'database' => 'infbyte_ha_lab',
        'username' => (string) getenv('LAB_HA_USER'),
        'password' => (string) getenv('LAB_HA_PASSWORD'),
        'encrypt' => true,
        'trust_server_certificate' => false,
    ]));
}

function haPrimary(Connection $db): string
{
    haCheck((int) $db->scalar("SELECT sys.fn_hadr_is_primary_replica(DB_NAME())") === 1, 'Listener did not reach primary.');
    $name = (string) $db->scalar('SELECT @@SERVERNAME');
    haCheck(preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{0,62}$/D', $name) === 1, 'SQL node name cannot be safely mapped to cluster member.');

    return $name;
}

$phase = $argv[1] ?? '';
$run = getenv('GITHUB_RUN_ID') . '_' . getenv('GITHUB_RUN_ATTEMPT');
haCheck(preg_match('/^[0-9]+_[0-9]+$/D', $run) === 1 && (string) getenv('LAB_HA_LISTENER') !== ''
    && (string) getenv('LAB_HA_USER') !== '' && (string) getenv('LAB_HA_PASSWORD') !== '', 'External fixture configuration missing.');
$table = 'lab_ha_' . $run;
$state = getenv('EVIDENCE') . '/ha-state.json';
if ($phase === 'seed') {
    $db = haConnection();
    $primary = haPrimary($db);
    $topology = $db->select("SELECT ag.cluster_type_desc, ag.required_synchronized_secondaries_to_commit, ar.availability_mode_desc, ar.failover_mode_desc, drs.synchronization_state_desc
        FROM sys.availability_groups ag JOIN sys.availability_databases_cluster adc ON adc.group_id=ag.group_id
        JOIN sys.availability_replicas ar ON ar.group_id=ag.group_id
        LEFT JOIN sys.dm_hadr_database_replica_states drs ON drs.replica_id=ar.replica_id AND drs.group_database_id=adc.group_database_id
        WHERE adc.database_name=DB_NAME()");
    haCheck(count($topology) === 3, 'Disposable external cluster must have three data replicas.');
    foreach ($topology as $row) {
        haCheck($row['cluster_type_desc'] === 'EXTERNAL' && (int) $row['required_synchronized_secondaries_to_commit'] >= 1
            && $row['availability_mode_desc'] === 'SYNCHRONOUS_COMMIT' && $row['failover_mode_desc'] === 'EXTERNAL'
            && $row['synchronization_state_desc'] === 'SYNCHRONIZED', 'Fixture lacks external synchronous failover policy.');
    }
    $db->statement("CREATE TABLE $table (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)");
    $callbacks = 0;
    foreach (range(1, 20) as $id) {
        $db->transaction(static function (Connection $active) use ($table, $id, &$callbacks): void {
            $active->statement("INSERT INTO $table (id, amount) VALUES (?, ?)", [$id, $id]);
            $active->afterCommit(static function () use (&$callbacks): void { ++$callbacks; });
        });
    }
    haCheck($callbacks === 20 && (int) $db->scalar("SELECT SUM(amount) FROM $table") === 210, 'Confirmed primary ledger seed failed.');
    haCheck(file_put_contents($state, json_encode(['primary' => $primary, 'table' => $table], JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'HA state unavailable.');
    echo "Three synchronized external replicas and 20 confirmed ledger writes verified\n";
} elseif ($phase === 'recover') {
    $before = json_decode(file_get_contents($state), true, flags: JSON_THROW_ON_ERROR);
    haCheck($before['table'] === $table, 'Run ownership mismatch.');
    $deadline = hrtime(true) + 180_000_000_000;
    $newPrimary = null;
    do {
        try {
            $db = haConnection();
            if ((int) $db->scalar("SELECT sys.fn_hadr_is_primary_replica(DB_NAME())") === 1) {
                $name = haPrimary($db);
                if ($name !== $before['primary']) {
                    $newPrimary = $name;
                    break;
                }
            }
        } catch (ConnectionException|QueryException) {
            // The same listener may be unavailable during cluster-owned transition.
        }
        usleep(500000);
    } while (hrtime(true) < $deadline);
    haCheck($newPrimary !== null, 'Automatic promotion through the original listener did not occur.');
    $rows = $db->select("SELECT id, amount FROM $table ORDER BY id");
    haCheck(array_map(static fn(array $row): array => [(int) $row['id'], (int) $row['amount']], $rows)
        === array_map(static fn(int $id): array => [$id, $id], range(1, 20)), 'Acknowledged ledger lost across automatic promotion.');
    $callbacks = 0;
    $db->transaction(static function (Connection $active) use ($table, &$callbacks): void {
        $active->statement("INSERT INTO $table (id, amount) VALUES (21, 21)");
        $active->afterCommit(static function () use (&$callbacks): void { ++$callbacks; });
    });
    $peer = haConnection();
    haCheck(haPrimary($peer) === $newPrimary && $callbacks === 1 && (int) $peer->scalar("SELECT COUNT(*) FROM $table") === 21
        && (int) $peer->scalar("SELECT SUM(amount) FROM $table") === 231, 'Fresh listener reconciliation failed.');
    haCheck(file_put_contents(getenv('EVIDENCE') . '/ha-recovered.json', json_encode(['primary' => $newPrimary, 'rows' => 21, 'sum' => 231], JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'Recovery evidence unavailable.');
    $peer->statement("DROP TABLE $table");
    echo "Cluster-managed promotion and unchanged-listener durable write recovery verified\n";
} else {
    throw new InvalidArgumentException('Unknown cluster acceptance phase.');
}
