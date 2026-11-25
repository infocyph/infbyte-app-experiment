<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\ConnectionException;

function databaseTlsWorkload(): void
{
    $root = (string) getenv('LAB_DB_TLS_ROOT');
    $settings = ['host' => 'localhost', 'sslmode' => 'verify-full', 'require_tls' => true];
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
