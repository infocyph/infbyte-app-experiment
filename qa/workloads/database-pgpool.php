<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\DBException;

function pgpoolConnection(int $port = 16433): Connection
{
    return new Connection(config(['port' => $port, 'timeout' => 5]));
}

function pgpoolAwaitReplica(int $expected): void
{
    $replica = pgpoolConnection(5433);
    waitFor(static function () use ($replica, $expected): bool {
        try {
            return balance($replica, 1) === $expected && balance($replica, 2) === 200 - $expected;
        } catch (DBException) {
            return false;
        }
    }, 'Physical standby did not reconcile.', 60);
}

function pgpoolSeed(Connection $db): void
{
    setup($db);
    pgpoolAwaitReplica(100);
}

function pgpoolWork(): void
{
    $db = pgpoolConnection();
    check((int) $db->scalar('SELECT inet_server_port()') === 5433 && balance($db, 1) === 100, 'Pgpool read did not use standby.');
    $effects = 0;
    for ($i = 0; $i < 20; ++$i) {
        $db->transaction(static function (Connection $active) use (&$effects): void {
            $active->update('UPDATE lab_balances SET balance = balance + 1 WHERE id = 1');
            check((int) $active->scalar('SELECT inet_server_port()') === 5432, 'Write transaction lost primary affinity.');
            $active->update('UPDATE lab_balances SET balance = balance - 1 WHERE id = 2');
            $active->beginTransaction();
            $active->update('UPDATE lab_balances SET balance = 0 WHERE id = 1');
            $active->rollbackTransaction();
            $active->afterCommit(static function () use (&$effects): void { ++$effects; });
        });
    }
    $primary = pgpoolConnection(5432);
    check($effects === 20 && $db->transactionLevel() === 0 && balance($primary, 1) === 120 && balance($primary, 2) === 80, 'Pgpool durable transaction reconciliation failed.');
    pgpoolAwaitReplica(120);
    check(balance(pgpoolConnection(), 1) === 120, 'Proxy reader did not reconcile.');
}

function pgpoolOutage(): void
{
    $db = pgpoolConnection();
    check((int) $db->scalar('SELECT inet_server_port()') === 5432 && balance($db, 1) === 120, 'Pgpool failed to fall back to primary.');
    $db->transaction(static function (Connection $active): void {
        $active->update('UPDATE lab_balances SET balance = balance + 1 WHERE id = 1');
        $active->update('UPDATE lab_balances SET balance = balance - 1 WHERE id = 2');
    });
    $primary = pgpoolConnection(5432);
    check(balance($primary, 1) === 121 && balance($primary, 2) === 79, 'Primary mutation during replica outage did not reconcile.');
}

function pgpoolRecover(): void
{
    pgpoolAwaitReplica(121);
    $db = pgpoolConnection();
    check((int) $db->scalar('SELECT inet_server_port()') === 5433 && balance($db, 1) === 121 && balance($db, 2) === 79, 'Explicitly reattached proxy reader did not reconcile.');
    echo "Physical Pgpool-II standby routing, primary transactions, fallback and reattachment passed\n";
}

function pgpoolPromoted(): void
{
    $db = pgpoolConnection();
    check((int) $db->scalar('SELECT inet_server_port()') === 5433 && in_array($db->scalar('SELECT pg_is_in_recovery()'), [false, 'f', 0, '0'], true), 'Stable proxy endpoint did not switch to promoted standby.');
    check(balance($db, 1) === 120 && balance($db, 2) === 80, 'Confirmed pre-failover writes were lost.');
    $effects = 0;
    $db->transaction(static function (Connection $active) use (&$effects): void {
        $active->update('UPDATE lab_balances SET balance = balance + 2 WHERE id = 1');
        $active->update('UPDATE lab_balances SET balance = balance - 2 WHERE id = 2');
        $active->afterCommit(static function () use (&$effects): void { ++$effects; });
    });
    $direct = pgpoolConnection(5433);
    check($effects === 1 && $db->transactionLevel() === 0 && balance($direct, 1) === 122 && balance($direct, 2) === 78, 'Post-failover write did not reconcile.');
    echo "Monitor-triggered fenced PostgreSQL promotion preserved confirmed data and stable proxy writes\n";
}
