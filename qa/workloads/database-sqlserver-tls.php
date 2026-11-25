<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\ConnectionException;

function sqlServerTlsWorkload(): void
{
    $settings = [
        'host' => 'localhost', 'encrypt' => true, 'trust_server_certificate' => false,
        'timeout' => 3, 'security' => ['require_tls' => true],
    ];
    $db = new Connection(config($settings));
    $encrypted = static fn(Connection $connection): bool => strtoupper((string) $connection->scalar(
        'SELECT encrypt_option FROM sys.dm_exec_connections WHERE session_id = @@SPID',
    )) === 'TRUE';
    check($encrypted($db), 'Verified SQL Server session did not negotiate encryption.');
    setup($db);
    $db->transaction(static function (Connection $transaction): void {
        $transaction->table('lab_balances')->where('id', '=', 1)->update(['balance' => 75]);
        $transaction->table('lab_balances')->where('id', '=', 2)->update(['balance' => 125]);
    });
    $db->reconnect(true);
    check($encrypted($db) && balance($db, 1) === 75 && balance($db, 2) === 125, 'TLS reconnect or committed balance failed.');
    $rejected = false;
    try {
        $wrongHost = new Connection(config(array_replace($settings, ['host' => '127.0.0.1'])));
        $wrongHost->scalar('SELECT 1');
    } catch (ConnectionException | PDOException) {
        $rejected = true;
    }
    check($rejected, 'Certificate without IP SAN accepted the IP identity.');
    $rejected = false;
    try {
        $plaintext = new Connection(config(array_replace($settings, ['encrypt' => false])));
        $plaintext->scalar('SELECT 1');
    } catch (ConnectionException | InvalidArgumentException) {
        $rejected = true;
    }
    check($rejected, 'Required TLS policy accepted encrypt=false.');
    check($encrypted($db) && balance($db, 1) + balance($db, 2) === 200, 'Rejected connections changed trusted state.');
    echo "Verified SQL Server TLS, wrong hostname, required policy, commit and reconnect passed\n";
}
