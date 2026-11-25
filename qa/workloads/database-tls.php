<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\ConnectionException;

function databaseTlsWorkload(): void
{
    $root = (string) getenv('LAB_DB_TLS_ROOT');
    if (in_array(getenv('DB_BACKEND'), ['mysql', 'mariadb'], true)) {
        mysqlFamilyTlsWorkload($root);

        return;
    }
    $settings = ['host' => 'localhost', 'sslmode' => 'verify-full', 'security' => ['require_tls' => true]];
    $db = new Connection(config($settings));
    $encrypted = static fn(Connection $connection): bool => in_array($connection->scalar('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()'), [true, 't', 1, '1'], true);
    check($encrypted($db), 'PostgreSQL did not negotiate TLS.');
    setup($db);
    $db->transaction(static function (Connection $transaction): void {
        $transaction->update('UPDATE lab_balances SET balance = balance - 25 WHERE id = ?', [1]);
        $transaction->update('UPDATE lab_balances SET balance = balance + 25 WHERE id = ?', [2]);
    });
    $db->reconnect(true);
    check($encrypted($db) && balance($db, 1) === 75 && balance($db, 2) === 125, 'TLS reconnect lost transport or committed state.');

    foreach ([
        ['host' => '127.0.0.1', 'ca' => $root . '/server.crt'],
        ['host' => 'localhost', 'ca' => $root . '/wrong.crt'],
    ] as $fault) {
        putenv('PGSSLROOTCERT=' . $fault['ca']);
        $rejected = false;
        try {
            $invalid = new Connection(config(array_replace($settings, ['host' => $fault['host']])));
            $invalid->scalar('SELECT 1');
        } catch (ConnectionException|PDOException) {
            $rejected = true;
        } finally {
            putenv('PGSSLROOTCERT=' . $root . '/server.crt');
        }
        check($rejected, 'Wrong hostname or CA accepted.');
    }
    $rejected = false;
    try {
        $plaintext = new Connection(config(array_replace($settings, ['sslmode' => 'disable'])));
        $plaintext->scalar('SELECT 1');
    } catch (ConnectionException|InvalidArgumentException) {
        $rejected = true;
    }
    check($rejected, 'Required TLS policy accepted sslmode=disable.');
    check($encrypted($db) && balance($db, 1) + balance($db, 2) === 200, 'Trusted session changed after rejected connections.');
    echo "PostgreSQL verified TLS, hostname/CA rejection, required transport policy and transactional reconnect passed\n";
}


function mysqlFamilyTlsWorkload(string $root): void
{
    $settings = [
        'host' => '127.0.0.1',
        'port' => 3308,
        'ssl_ca' => $root . '/ca.crt',
        'ssl_verify_server_cert' => true,
        'security' => ['require_tls' => true],
    ];
    $db = new Connection(config($settings));
    check(mysqlFamilyEncrypted($db), 'MySQL-family connection did not negotiate TLS.');
    setup($db);
    $db->transaction(static function (Connection $transaction): void {
        $transaction->update('UPDATE lab_balances SET balance = balance - 25 WHERE id = ?', [1]);
        $transaction->update('UPDATE lab_balances SET balance = balance + 25 WHERE id = ?', [2]);
    });
    $db->reconnect(true);
    check(mysqlFamilyEncrypted($db) && balance($db, 1) === 75 && balance($db, 2) === 125, 'TLS reconnect lost encryption or committed state.');

    foreach ([
        ['host' => '127.0.0.2'],
        ['ssl_ca' => $root . '/wrong.crt'],
    ] as $fault) {
        $rejected = false;
        try {
            $invalid = new Connection(config(array_replace($settings, $fault)));
            $invalid->scalar('SELECT 1');
        } catch (ConnectionException | PDOException) {
            $rejected = true;
        }
        check($rejected, 'Wrong server identity or CA accepted.');
    }

    $rejected = false;
    try {
        config(array_replace($settings, ['ssl_ca' => null]));
    } catch (ConnectionException) {
        $rejected = true;
    }
    check($rejected, 'Required TLS policy accepted configuration without TLS material.');
    check(mysqlFamilyEncrypted($db) && balance($db, 1) + balance($db, 2) === 200, 'Trusted connection changed after rejected connections.');
    echo "MySQL-family verified TLS, identity/CA rejection, required transport policy and transactional reconnect passed\n";
}

function mysqlFamilyEncrypted(Connection $db): bool
{
    $rows = $db->select("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
    $row = $rows[0] ?? [];
    $value = ((array) $row)['Value'] ?? null;

    return is_string($value) && $value !== '';
}
