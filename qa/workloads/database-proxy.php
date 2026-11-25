<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;

function proxySeed(Connection $db): void
{
    $db->statement('CREATE TABLE lab_proxy_ledger (id INTEGER PRIMARY KEY, worker INTEGER NOT NULL, sequence INTEGER NOT NULL)');
}

function proxyWorker(int $worker): void
{
    check($worker >= 1 && $worker <= 8, 'Invalid proxy worker.');
    $db = new Connection(config(['port' => 16432]));
    $session = getenv('SCENARIO') === 'db-proxy-session';
    $backend = (int) $db->scalar('SELECT pg_backend_pid()');
    $effects = 0;
    for ($i = 1; $i <= 50; ++$i) {
        $id = $worker * 1000 + $i;
        $db->transaction(static function (Connection $conn) use ($worker, $i, $id, &$effects, $session, $backend): void {
            $owner = (int) $conn->scalar('SELECT pg_backend_pid()');
            $conn->table('lab_proxy_ledger')->insert(['id' => $id, 'worker' => $worker, 'sequence' => $i]);
            $conn->beginTransaction();
            $conn->table('lab_proxy_ledger')->where('id', '=', $id)->update(['sequence' => -1]);
            $conn->rollbackTransaction();
            check((int) $conn->table('lab_proxy_ledger')->where('id', '=', $id)->value('sequence') === $i, 'Proxy savepoint failed.');
            check((int) $conn->scalar('SELECT pg_backend_pid()') === $owner, 'Backend changed inside transaction.');
            if ($session) {
                check($owner === $backend, 'Session pool lost backend affinity.');
            }
            $conn->afterCommit(static function () use (&$effects): void { ++$effects; });
        });
        check((int) $db->table('lab_proxy_ledger')->where('id', '=', $id)->value('sequence') === $i, 'Native prepared query or committed data failed.');
    }
    check($effects === 50 && $db->transactionLevel() === 0, 'Proxy callback or transaction state leaked.');
    echo "Worker $worker: 50 commits, savepoints and prepared readbacks passed\n";
}

function proxyVerify(Connection $db): void
{
    check($db->table('lab_proxy_ledger')->count() === 400, 'Proxy durable count mismatch.');
    for ($worker = 1; $worker <= 8; ++$worker) {
        $rows = $db->table('lab_proxy_ledger')->where('worker', '=', $worker)->orderBy('sequence')->get();
        check(array_map('intval', array_column($rows, 'sequence')) === range(1, 50), 'Proxy worker ledger mismatch.');
    }
    echo "400 native prepared transactions and callbacks across real PgBouncer reconciled\n";
}
