<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;

function sqlServerAgSeed(Connection $db): void
{
    setup($db);
    $db->statement('CREATE TABLE lab_ag_ledger (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)');
    $effects = 0;
    for ($id = 1; $id <= 20; ++$id) {
        $db->transaction(function (Connection $db) use ($id, &$effects): void {
            $db->update('UPDATE lab_balances SET balance = balance - 1 WHERE id = 1');
            $db->update('UPDATE lab_balances SET balance = balance + 1 WHERE id = 2');
            $db->statement('INSERT INTO lab_ag_ledger (id, amount) VALUES (?, ?)', [$id, 1]);
            $db->afterCommit(static function () use (&$effects): void { ++$effects; });
        });
    }
    check($effects === 20, 'Primary commit callback count mismatch.');
    check(balance($db, 1) === 80 && balance($db, 2) === 120, 'Primary balance mismatch.');
}

function sqlServerAgRead(): void
{
    $reader = new Connection(config(['port' => 1434]));
    check((int) $reader->scalar("SELECT sys.fn_hadr_is_primary_replica('experiment')") === 0, 'Reader is not an AG secondary.');
    waitFor(static fn(): bool => (int) $reader->scalar('SELECT COUNT(*) FROM lab_ag_ledger') === 20, 'Secondary did not replay ledger.', 60);
    check(balance($reader, 1) === 80 && balance($reader, 2) === 120, 'Secondary balance mismatch.');
    check((int) $reader->scalar('SELECT SUM(amount) FROM lab_ag_ledger') === 20, 'Secondary ledger mismatch.');
    echo "Synchronized secondary read reconciled.", PHP_EOL;
}

function sqlServerAgPromoted(): void
{
    $db = new Connection(config(['port' => 1434]));
    check((int) $db->scalar("SELECT sys.fn_hadr_is_primary_replica('experiment')") === 1, 'Target is not primary.');
    check((int) $db->scalar('SELECT COUNT(*) FROM lab_ag_ledger') === 20, 'Previously committed ledger lost.');
    check(balance($db, 1) === 80 && balance($db, 2) === 120, 'Previously committed balances lost.');
    $effects = [];
    $db->transaction(function (Connection $db) use (&$effects): void {
        $db->statement('INSERT INTO lab_ag_ledger (id, amount) VALUES (21, 5)');
        $db->update('UPDATE lab_balances SET balance = balance - 5 WHERE id = 1');
        $db->update('UPDATE lab_balances SET balance = balance + 5 WHERE id = 2');
        $db->afterCommit(static function () use (&$effects): void { $effects[] = 'committed'; });
    });
    $observer = new Connection(config(['port' => 1434]));
    check($effects === ['committed'], 'Promoted primary callback mismatch.');
    check(balance($observer, 1) === 75 && balance($observer, 2) === 125, 'Promoted primary balance mismatch.');
    check((int) $observer->scalar('SELECT COUNT(*) FROM lab_ag_ledger') === 21, 'Promoted primary ledger count mismatch.');
    check((int) $observer->scalar('SELECT SUM(amount) FROM lab_ag_ledger') === 25, 'Promoted primary ledger sum mismatch.');
    echo "Manual promotion and explicit endpoint replacement reconciled.", PHP_EOL;
}
