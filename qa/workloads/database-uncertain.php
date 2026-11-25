<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\DBException;

function uncertainCommitWorkload(Connection $direct): void
{
    setup($direct);
    $direct->statement('CREATE TABLE lab_commit_ledger (id VARCHAR(40) PRIMARY KEY, amount INTEGER NOT NULL)');
    $port = (int) getenv('LAB_COMMIT_PROXY_PORT');
    check($port > 0, 'Proxy port missing.');
    // The protocol-aware fault proxy is an isolated plaintext loopback fixture.
    $settings = ['port' => $port, 'timeout' => 3];
    if ($direct->getDriverName() === 'pgsql') {
        $settings['sslmode'] = 'disable';
    }
    $db = new Connection(config($settings));
    $attempts = 0;
    $effects = 0;
    $failure = false;
    try {
        $db->transaction(static function (Connection $active) use (&$attempts, &$effects): void {
            ++$attempts;
            $active->table('lab_commit_ledger')->insert(['id' => 'checkout-42', 'amount' => 10]);
            $active->update('UPDATE lab_balances SET balance = balance - 10 WHERE id = ?', [1]);
            $active->update('UPDATE lab_balances SET balance = balance + 10 WHERE id = ?', [2]);
            $active->afterCommit(static function () use (&$effects): void { ++$effects; });
        }, attempts: 3);
    } catch (DBException) {
        $failure = true;
    }
    check(is_file(getenv('LAB_COMMIT_PROXY_ROOT') . '/ack-dropped'), 'Fault never reached a real COMMIT acknowledgement.');
    check($failure && $attempts === 1 && $effects === 0, 'Ambiguous commit replayed or reported a confirmed outcome.');
    check($direct->table('lab_commit_ledger')->count() === 1 && balance($direct, 1) === 90 && balance($direct, 2) === 110, 'Committed database state did not reconcile.');
    // An independent execution must resolve the uncertain result by durable identity.
    $db->reconnect(true);
    check($db->transactionLevel() === 0, 'Uncertain transaction state leaked.');
    $result = $db->transaction(static function (Connection $active): string {
        $active->table('lab_balances')->where('id', '=', 1)->lockForUpdate()->first();
        $row = $active->table('lab_commit_ledger')->where('id', '=', 'checkout-42')->first();
        if ($row !== null) {
            return 'already-committed';
        }
        throw new LogicException('Committed idempotency identity was lost.');
    });
    check($result === 'already-committed' && balance($direct, 1) + balance($direct, 2) === 200, 'Recovery did not preserve financial invariants.');
    echo "Actual dropped database COMMIT acknowledgement, no automatic replay/callback and durable idempotency reconciliation passed\n";
}
