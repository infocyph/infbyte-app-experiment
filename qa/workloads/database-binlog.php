<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\DBException;

function binlogConnection(int $port): Connection
{
    return new Connection(config(['port' => $port]));
}

function binlogRouted(bool $sticky): Connection
{
    return new Connection(config([
        'port' => 23306,
        'read' => [['port' => 23307, 'weight' => 1]],
        'read_strategy' => 'round_robin',
        'sticky' => $sticky,
        'read_health_cooldown' => 5,
        'read_session_read_only' => true,
    ]));
}

function binlogServer(Connection $db): int
{
    return (int) $db->scalar('SELECT @@server_id');
}

function binlogWait(int $expected): void
{
    $reader = binlogConnection(23307);
    waitFor(static function () use ($reader, $expected): bool {
        try {
            return binlogServer($reader) === 42 && balance($reader, 1) === $expected;
        } catch (DBException) {
            return false;
        }
    }, 'Reader did not apply source binlog.', 60);
}

function binlogSeed(): void
{
    $primary = binlogConnection(23306);
    check(binlogServer($primary) === 41, 'Unexpected source.');
    setup($primary);
    binlogWait(100);
    $reader = binlogConnection(23307);
    check((int) $reader->scalar('SELECT @@read_only') === 1, 'Reader was not read-only.');
    $routed = binlogRouted(true);
    check(binlogServer($routed) === 42 && balance($routed, 1) === 100, 'Initial read did not route to actual replica.');
    echo "Real binary-log replication and reader routing passed\n";
}

function binlogLag(): void
{
    $primary = binlogConnection(23306);
    $primary->table('lab_balances')->where('id', '=', 1)->update(['balance' => 110]);
    $routed = binlogRouted(true);
    check(binlogServer($routed) === 42 && balance($routed, 1) === 100, 'Paused reader did not demonstrate actual stale data.');
    $routed->table('lab_balances')->where('id', '=', 1)->update(['balance' => 111]);
    check(binlogServer($routed) === 41 && balance($routed, 1) === 111, 'Sticky read-after-write failed under real lag.');
    check(balance(binlogConnection(23307), 1) === 100, 'Reader unexpectedly caught up while SQL thread paused.');
    echo "Paused replica lag and sticky primary consistency passed\n";
}

function binlogCatchup(): void
{
    binlogWait(111);
    $routed = binlogRouted(false);
    check(binlogServer($routed) === 42 && balance($routed, 1) === 111, 'Reader data did not reconcile after resume.');
    echo "Reader caught up after real SQL-thread resume\n";
}

function binlogOutage(): void
{
    $routed = binlogRouted(false);
    check(binlogServer($routed) === 41 && balance($routed, 1) === 111, 'Reader outage did not fall back to source.');
    $routed->table('lab_balances')->where('id', '=', 1)->update(['balance' => 112]);
    check(balance(binlogConnection(23306), 1) === 112, 'Write failed while reader unavailable.');
    echo "Actual reader loss, source fallback and write passed\n";
}

function binlogRecover(): void
{
    binlogWait(112);
    $routed = binlogRouted(false);
    check(binlogServer($routed) === 42 && balance($routed, 1) === 112, 'Restarted reader was not selected/reconciled.');
    echo "Reader restart, replication recovery and new execution routing passed\n";
}
