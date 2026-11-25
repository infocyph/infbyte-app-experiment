<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\TransactionException;

function windowQueryContracts(Connection $db): void
{
    seedRows($db, 20);
    check($db->supportsWindowFunctions(), 'Supported modern database did not advertise window functions.');
    $query = $db->table('lab_records')->select('id', 'tenant_id', 'amount')
        ->selectWindow('row_number()', 'ordinal', ['tenant_id'], ['amount DESC', 'id ASC'])
        ->selectWindow('dense_rank()', 'amount_rank', ['tenant_id'], ['amount DESC'])
        ->selectWindow($db->raw('SUM(amount)'), 'tenant_total', ['tenant_id'])
        ->orderBy('tenant_id')->orderByDesc('amount')->orderBy('id');
    $rows = $query->get();
    $expected = [];
    for ($tenant = 1; $tenant <= 2; ++$tenant) {
        $ids = array_values(array_filter(range(1, 20), static fn(int $id): bool => $id % 2 + 1 === $tenant));
        usort($ids, static fn(int $a, int $b): int => ($b % 10 <=> $a % 10) ?: ($a <=> $b));
        foreach ($ids as $index => $id) {
            $expected[] = [$id, $tenant, $id % 10, $index + 1, intdiv($index, 2) + 1, $tenant === 1 ? 40 : 50];
        }
    }
    $actual = array_map(static fn(array $row): array => array_map('intval', [$row['id'], $row['tenant_id'], $row['amount'], $row['ordinal'], $row['amount_rank'], $row['tenant_total']]), $rows);
    check($actual === $expected, 'Window ranking/partition/sum disagrees with independent oracle.');
    foreach ([
        static fn() => $db->table('lab_records')->selectWindow('SUM(amount)', 'value'),
        static fn() => $db->table('lab_records')->selectWindow('row_number()', 'bad-alias'),
        static fn() => $db->table('lab_records')->selectWindow('rank()', 'ranked', orderBy: ['id DESC, amount']),
    ] as $invalid) {
        rejected($invalid, 'Invalid structured window expression accepted.');
    }
    check($db->table('lab_records')->count() === 20, 'Window rejection altered records.');
    echo "Structured window ranking, expression, partition and identifier boundaries passed\n";
}

function recursiveQueryContracts(Connection $db): void
{
    $db->statement('CREATE TABLE lab_tree (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, parent_id INTEGER NULL)');
    $db->table('lab_tree')->insert([
        ['id' => 1, 'tenant_id' => 1, 'parent_id' => null],
        ['id' => 2, 'tenant_id' => 1, 'parent_id' => 1],
        ['id' => 3, 'tenant_id' => 1, 'parent_id' => 1],
        ['id' => 4, 'tenant_id' => 1, 'parent_id' => 2],
        ['id' => 5, 'tenant_id' => 1, 'parent_id' => 4],
        ['id' => 6, 'tenant_id' => 2, 'parent_id' => 2],
        ['id' => 7, 'tenant_id' => 2, 'parent_id' => null],
    ]);
    // Bound seed and recursive depth: never concatenate request input into SQL.
    $recursive = 'SELECT id, tenant_id, 0 AS depth FROM lab_tree WHERE id = ? AND tenant_id = ? UNION ALL SELECT t.id, t.tenant_id, p.depth + 1 FROM lab_tree t INNER JOIN descendants p ON t.parent_id = p.id AND t.tenant_id = p.tenant_id WHERE p.depth < ?';
    $query = static fn(int $tenant, int $root, int $depth) => $db->query()->withRecursive('descendants', $recursive, [$root, $tenant, $depth])->from('descendants')->select('id', 'depth')->orderBy('id');
    $project = static fn(array $rows): array => array_map(static fn(array $row): array => [(int) $row['id'], (int) $row['depth']], $rows);
    check($project($query(1, 1, 4)->get()) === [[1, 0], [2, 1], [3, 1], [4, 2], [5, 3]], 'Recursive traversal escaped tenant or lost descendants.');
    check($project($query(1, 1, 1)->get()) === [[1, 0], [2, 1], [3, 1]], 'Depth bound failed.');
    check($project($query(2, 7, 4)->get()) === [[7, 0]], 'Foreign tree traversal differed.');
    check($query(2, 1, 4)->get() === [] && $db->table('lab_tree')->count() === 7, 'Foreign root or query mutation accepted.');
    echo "Bound recursive CTE, tenant correlation and depth limits passed\n";
}

function generatedIdentityContracts(Connection $db): void
{
    $identity = match ($db->getDriverName()) {
        'pgsql' => 'BIGSERIAL PRIMARY KEY',
        'mysql', 'mariadb' => 'BIGINT AUTO_INCREMENT PRIMARY KEY',
        'mssql' => 'BIGINT IDENTITY(1,1) PRIMARY KEY',
        'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        default => throw new RuntimeException('Unknown identity dialect.'),
    };
    $db->statement("CREATE TABLE lab_generated (id $identity, label VARCHAR(80) NOT NULL UNIQUE)");
    $first = $db->table('lab_generated')->insertGetId(['label' => 'first']);
    $second = $db->table('lab_generated')->insertReturning(['label' => 'second']);
    check(ctype_digit($first) && (int) $first > 0 && is_array($second) && (int) $second['id'] > (int) $first, 'Generated identity missing or duplicated.');
    $peer = new Connection(config());
    check($peer->table('lab_generated')->where('id', '=', (int) $first)->value('label') === 'first' && $peer->table('lab_generated')->where('id', '=', (int) $second['id'])->value('label') === 'second', 'Generated identities disagree with independent connection.');
    if ($db->supportsReturning()) {
        $bulk = $db->table('lab_generated')->insertReturning([['label' => 'third'], ['label' => 'fourth']]);
        check(is_array($bulk) && (int) $bulk['id'] > (int) $second['id'] && $peer->table('lab_generated')->count() === 4, 'Native bulk returning did not insert both rows.');
    } else {
        rejected(static fn() => $db->table('lab_generated')->insertReturning([['label' => 'third'], ['label' => 'fourth']]), 'Unsupported bulk RETURNING silently inserted rows.');
        check($peer->table('lab_generated')->count() === 2, 'Rejected bulk returning published rows.');
    }
    $before = $peer->table('lab_generated')->count();
    try {
        $db->transaction(static function (Connection $active): void {
            check((int) $active->table('lab_generated')->insertGetId(['label' => 'rollback']) > 0, 'Transactional identity missing.');
            throw new DomainException('rollback identity');
        });
        throw new RuntimeException('Rollback signal lost.');
    } catch (TransactionException $failure) {
        check($failure->getPrevious() instanceof DomainException, 'Unexpected identity rollback failure.');
    }
    check($peer->table('lab_generated')->count() === $before && !$peer->table('lab_generated')->where('label', '=', 'rollback')->exists(), 'Returned identity escaped rollback.');
    echo "Native/fallback generated IDs, unsupported bulk boundary and rollback reconciliation passed\n";
}

function upsertReturningContracts(Connection $db): void
{
    $db->statement('CREATE TABLE lab_returns (tenant_id INTEGER NOT NULL, sku INTEGER NOT NULL, amount INTEGER NOT NULL, label VARCHAR(60) NOT NULL, PRIMARY KEY (tenant_id, sku))');
    $db->table('lab_returns')->insert([
        ['tenant_id' => 1, 'sku' => 1, 'amount' => 10, 'label' => 'original'],
        ['tenant_id' => 2, 'sku' => 1, 'amount' => 90, 'label' => 'foreign'],
    ]);
    $query = $db->table('lab_returns');
    $returned = $query->upsertReturning([
        ['tenant_id' => 1, 'sku' => 1, 'amount' => 11, 'label' => 'ignored-on-update'],
        ['tenant_id' => 1, 'sku' => 2, 'amount' => 20, 'label' => 'new'],
    ], ['tenant_id', 'sku'], ['amount'], ['tenant_id', 'sku', 'amount', 'label']);
    $project = static function (array $rows): array {
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['tenant_id'] . ':' . (int) $row['sku']] = [(int) $row['amount'], $row['label']];
        }
        ksort($result);
        return $result;
    };
    check(count($returned) === 2 && $project($returned) === ['1:1' => [11, 'original'], '1:2' => [20, 'new']], 'Upsert-returning composite-key correlation or update projection differs.');
    $peer = new Connection(config());
    check($project($peer->table('lab_returns')->get()) === ['1:1' => [11, 'original'], '1:2' => [20, 'new'], '2:1' => [90, 'foreign']], 'Returned rows disagree with committed state or cross tenant.');
    try {
        $db->transaction(static function (Connection $active): void {
            $rows = $active->table('lab_returns')->upsertReturning(['tenant_id' => 1, 'sku' => 1, 'amount' => 999, 'label' => 'ignored'], ['tenant_id', 'sku'], ['amount'], ['amount']);
            check(count($rows) === 1 && (int) $rows[0]['amount'] === 999, 'Transactional returning did not observe update.');
            throw new DomainException('rollback upsert');
        });
        throw new RuntimeException('Rollback signal lost.');
    } catch (TransactionException $failure) {
        check($failure->getPrevious() instanceof DomainException, 'Unexpected upsert rollback failure.');
    }
    check((int) $peer->table('lab_returns')->where('tenant_id', '=', 1)->where('sku', '=', 1)->value('amount') === 11, 'Returning escaped rollback.');
    echo "Composite-key native/fallback upsert returning, exact rows and rollback passed\n";
}

function compilerPayloadContracts(Connection $db): void
{
    seedRows($db, 10);
    $compiler = $db->getCompiler();
    $base = $db->table('lab_records')->where('tenant_id', '=', 1)->where('id', '=', 2);
    $select = $base->toPayload();
    $update = $base->toUpdatePayload(['amount' => 77]);
    $delete = $base->toDeletePayload();
    check($select->type !== $update->type && $update->type !== $delete->type && $select->bindings === [1, 2], 'Payload mutation or binding provenance differs.');
    $compiled = $compiler->compile($update);
    check($compiled->bindings === [77, 1, 2], 'Compiled update binding order differs.');
    $peer = new Connection(config());
    check($db->runCompiled($compiled)->rowCount === 1 && (int) $peer->table('lab_records')->where('id', '=', 2)->value('amount') === 77, 'Direct compiled mutation did not reconcile.');
    $rows = $db->runCompiled($compiler->compile($select))->rows;
    check(count($rows) === 1 && (int) $rows[0]['amount'] === 77, 'Original immutable select payload no longer selects.');
    $db->transaction(static function (Connection $active) use ($delete): void {
        check($active->runCompiled($active->getCompiler()->compile($delete))->rowCount === 1, 'Compiled delete did not affect expected row.');
    });
    check(!$peer->table('lab_records')->where('id', '=', 2)->exists() && $peer->table('lab_records')->count() === 9, 'Compiled deletion crossed predicate boundary.');
    $insert = $db->table('lab_records')->toInsertPayload(['id' => 22, 'tenant_id' => 1, 'amount' => 88, 'label' => 'compiled']);
    check($db->runCompiled($compiler->compile($insert))->rowCount === 1 && $peer->table('lab_records')->where('id', '=', 22)->value('label') === 'compiled', 'Compiled insertion did not reconcile.');
    check($base->getBindings() === [1, 2] && $peer->table('lab_records')->count() === 10, 'Direct compiler execution mutated builder or unrelated rows.');
    echo "Immutable compiler payloads, mutation binding order and direct execution reconciled\n";
}
