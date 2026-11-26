<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\MigrationException;
use Infocyph\DBLayer\Migration\SeedContext;
use Infocyph\DBLayer\Migration\SeedRunner;
use Infocyph\DBLayer\Migration\Seeder;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

function databaseConditionalBuilderContracts(Connection $db): void
{
    seedRows($db, 20);
    $base = $db->table('lab_records')->where('tenant_id', '=', 1)->orderBy('id');
    $calls = [];
    $branch = $base->cloneBuilder()
        ->when(4, static function ($query, int $value) use (&$calls): void {
            $calls[] = ['when', $value];
            $query->where('amount', '>=', $value);
        }, static fn() => throw new LogicException('Incorrect when fallback'))
        ->unless(false, static function ($query, bool $value) use (&$calls): void {
            $calls[] = ['unless', $value];
            $query->where('amount', '<=', 6);
        });
    $ids = static fn($query): array => array_map('intval', $query->pluck('id'));
    check($ids($branch) === [4, 6, 14, 16] && $calls === [['when', 4], ['unless', false]], 'Conditional builder callback or range mismatch.');
    $fallback = $base->cloneBuilder()->when(false, static fn() => throw new LogicException('False branch ran'),
        static fn($q) => $q->where('amount', '=', 0))
        ->unless(true, static fn() => throw new LogicException('True unless branch ran'), static fn($q) => $q->where('id', '>', 10));
    check($ids($fallback) === [20], 'Conditional fallback did not compose.');
    check($ids($base) === range(2, 20, 2) && $base->getBindings() === [1], 'Branches changed original builder state.');
    check($ids($base->newQuery()->from('lab_records')->where('tenant_id', '=', 2)->orderBy('id')) === range(1, 19, 2), 'New builder retained prior tenant predicate.');
    check((int) $base->cloneBuilder()->firstWhere('amount', '=', 8)['id'] === 8, 'FirstWhere did not retain tenant filter.');
    echo "Conditional query callbacks, isolated branches and fresh builder passed\n";
}

function databaseExistsContracts(Connection $db): void
{
    $db->statement('CREATE TABLE lab_exist_order (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
    $db->statement('CREATE TABLE lab_exist_receipt (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, order_id INTEGER NOT NULL, amount INTEGER NOT NULL)');
    $db->table('lab_exist_order')->insert([['id' => 1, 'tenant_id' => 1], ['id' => 2, 'tenant_id' => 1], ['id' => 3, 'tenant_id' => 1], ['id' => 4, 'tenant_id' => 2]]);
    $db->table('lab_exist_receipt')->insert([
        ['id' => 1, 'tenant_id' => 1, 'order_id' => 1, 'amount' => 10],
        ['id' => 2, 'tenant_id' => 1, 'order_id' => 1, 'amount' => 20],
        ['id' => 3, 'tenant_id' => 2, 'order_id' => 2, 'amount' => 99],
        ['id' => 4, 'tenant_id' => 1, 'order_id' => 3, 'amount' => 3],
    ]);
    $subquery = static fn($q) => $q->from('lab_exist_receipt')->as('r')->select('r.id')
        ->whereRaw('r.order_id = o.id AND r.tenant_id = o.tenant_id')->where('r.amount', '>=', 10);
    $base = $db->table('lab_exist_order')->as('o')->select('o.id')->where('o.tenant_id', '=', 1)->orderBy('o.id');
    $yes = $base->cloneBuilder()->whereExists($subquery);
    $no = $base->cloneBuilder()->whereExists($subquery, not: true);
    check(array_map('intval', $yes->pluck('id')) === [1] && array_map('intval', $no->pluck('id')) === [2, 3], 'Correlated EXISTS/NOT EXISTS crossed tenant or duplicated parent.');
    check($yes->getBindings() === [1, 10] && $no->getBindings() === [1, 10] && $base->getBindings() === [1], 'Correlated subquery bindings diverged.');
    check($db->table('lab_exist_order')->count() === 4 && $db->table('lab_exist_receipt')->count() === 4, 'Read altered persisted data.');
    echo "Correlated existence, negation and tenant-bound subquery bindings passed\n";
}

function databaseCrossJoinContracts(Connection $db): void
{
    $db->statement('CREATE TABLE lab_cross_product (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
    $db->statement('CREATE TABLE lab_cross_variant (id INTEGER PRIMARY KEY, active INTEGER NOT NULL)');
    $db->table('lab_cross_product')->insert([['id' => 1, 'tenant_id' => 1], ['id' => 2, 'tenant_id' => 1], ['id' => 3, 'tenant_id' => 2]]);
    $db->table('lab_cross_variant')->insert([['id' => 10, 'active' => 1], ['id' => 20, 'active' => 0], ['id' => 30, 'active' => 1]]);
    $result = $db->table('lab_cross_product')->as('p')->crossJoinAs('lab_cross_variant', 'v')
        ->select('p.id')->addSelectAs('v.id', 'variant')->where('p.tenant_id', '=', 1)
        ->where('v.active', '=', 1)->orderBy('p.id')->orderBy('v.id')->get();
    $actual = array_map(static fn(array $row): array => [(int) $row['id'], (int) $row['variant']], $result);
    check($actual === [[1, 10], [1, 30], [2, 10], [2, 30]], 'Aliased cross join multiplicity or bound filtering changed.');
    check($db->table('lab_cross_product')->crossJoin('lab_cross_variant')->count() === 9, 'Unfiltered Cartesian count mismatch.');
    echo "Native Cartesian products and structured alias projection passed\n";
}

function databaseDistinctContracts(Connection $db): void
{
    seedRows($db, 20);
    $rows = $db->table('lab_records')->select('tenant_id', 'amount')->distinct()->orderBy('tenant_id')->orderBy('amount')->get();
    $actual = array_map(static fn(array $row): array => [(int) $row['tenant_id'], (int) $row['amount']], $rows);
    $expected = [];
    foreach ([1, 2] as $tenant) {
        foreach (range($tenant - 1, 9, 2) as $amount) {
            $expected[] = [$tenant, $amount];
        }
    }
    check($actual === $expected, 'Distinct tuple projection changed multiplicity.');
    $base = $db->table('lab_records')->where('tenant_id', '=', 1);
    check($base->cloneBuilder()->count() === 10 && (float) $base->cloneBuilder()->avg('amount') === 4.0
        && (float) $base->cloneBuilder()->sum('amount') === 40.0, 'Aggregate reference mismatch.');
    $empty = $base->cloneBuilder()->where('id', '>', 1000);
    check(!$empty->cloneBuilder()->exists() && $empty->cloneBuilder()->count() === 0
        && $empty->cloneBuilder()->first() === null && $empty->cloneBuilder()->value('amount') === null, 'Empty projection did not preserve absence.');
    check($base->cloneBuilder()->orderBy('id')->forPage(2, 3)->pluck('id') !== [], 'Offset page empty.');
    check(array_map('intval', $base->cloneBuilder()->orderBy('id')->forPage(2, 3)->pluck('id')) === [8, 10, 12], 'Page offset mismatch.');
    echo "Distinct tuples, native aggregates, absent results and explicit paging passed\n";
}

final class LabDatabaseSeedChild implements Seeder
{
    public function run(Connection $connection, SeedContext $context): void
    {
        check($context->connection() === $connection, 'Nested seeder connection ownership changed.');
        $connection->table('lab_seed')->insert(['id' => 2, 'label' => 'child']);
    }
}

function databaseSeedContracts(Connection $db, bool $transactional): void
{
    $db->statement('CREATE TABLE lab_seed (id INTEGER PRIMARY KEY, label VARCHAR(40) NOT NULL)');
    $runner = new SeedRunner($db);
    $peer = new Connection(config());
    $seed = static function (Connection $connection, SeedContext $context): void {
        check($context->connection() === $connection, 'Seed context connection differed.');
        $connection->table('lab_seed')->insert(['id' => 1, 'label' => 'parent']);
        check($context->call([new LabDatabaseSeedChild(), static function (Connection $child): void {
            $child->table('lab_seed')->insert(['id' => 3, 'label' => 'nested']);
        }]) === 2, 'Nested seeder count differed.');
    };
    $tail = static fn(Connection $connection) => $connection->table('lab_seed')->insert(['id' => 4, 'label' => 'tail']);
    check($runner->run([$seed, $tail], $transactional) === 4, 'Seed execution count differs from actual units.');
    check($peer->table('lab_seed')->orderBy('id')->pluck('label') === ['parent', 'child', 'nested', 'tail'], 'Independent durable seed data differs.');
    try {
        $runner->run([static fn(Connection $connection) => $connection->table('lab_seed')->insert(['id' => 5, 'label' => 'invalid']), new stdClass()], $transactional);
        throw new LogicException('Invalid manifest accepted.');
    } catch (InvalidArgumentException) {
        check($peer->table('lab_seed')->count() === 4, 'Invalid manifest executed a prefix.');
    }
    $failed = false;
    try {
        $runner->run([
            static fn(Connection $connection) => $connection->table('lab_seed')->insert(['id' => 5, 'label' => 'partial']),
            static fn() => throw new DomainException('seeder-rejected'),
        ], $transactional);
    } catch (Throwable $failure) {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            $failed = $failed || $cause instanceof DomainException;
        }
        if (!$failed) {
            throw $failure;
        }
    }
    check($failed && $peer->table('lab_seed')->count() === ($transactional ? 4 : 5), 'Seed failure durability did not match transaction policy.');
    check($runner->run([static fn(Connection $connection) => $connection->table('lab_seed')->insert(['id' => 6, 'label' => 'recovered'])], $transactional) === 1
        && $peer->table('lab_seed')->where('id', '=', 6)->value('label') === 'recovered', 'Seed runner did not recover.');
    echo "Nested seed execution, manifest validation and explicit transaction policy passed\n";
}

function databaseCompositeForeignContracts(Connection $db): void
{
    if ($db->getDriverName() === 'sqlite') {
        $db->getPdo()->exec('PRAGMA foreign_keys = ON');
    }
    $schema = new SchemaManager($db);
    $schema->create('lab_comp_parent', static function (Blueprint $table): void {
        $table->integer('tenant_id');
        $table->integer('order_id');
        $table->primary(['tenant_id', 'order_id']);
    });
    $schema->create('lab_comp_child', static function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->integer('tenant_id');
        $table->integer('order_id');
        $table->foreign(['tenant_id', 'order_id'], 'lab_comp_fk')->references(['tenant_id', 'order_id'])
            ->on('lab_comp_parent')->onDelete('cascade')->onUpdate('cascade');
    });
    $db->table('lab_comp_parent')->insert([['tenant_id' => 1, 'order_id' => 7], ['tenant_id' => 2, 'order_id' => 7]]);
    $db->table('lab_comp_child')->insert([['id' => 1, 'tenant_id' => 1, 'order_id' => 7], ['id' => 2, 'tenant_id' => 2, 'order_id' => 7]]);
    rejected(static fn() => $db->table('lab_comp_child')->insert(['id' => 3, 'tenant_id' => 3, 'order_id' => 7]), 'Composite FK admitted cross-tenant orphan.');
    rejected(static fn() => $db->table('lab_comp_parent')->insert(['tenant_id' => 1, 'order_id' => 7]), 'Composite primary key accepted duplicate.');
    check($db->table('lab_comp_parent')->where('tenant_id', '=', 1)->where('order_id', '=', 7)->update(['order_id' => 8]) === 1, 'Composite parent update failed.');
    $peer = new Connection(config());
    check((int) $peer->table('lab_comp_child')->where('id', '=', 1)->value('order_id') === 8
        && (int) $peer->table('lab_comp_child')->where('id', '=', 2)->value('order_id') === 7, 'Composite cascade crossed tenant.');
    try {
        $schema->dropAllTables();
        throw new LogicException('Destructive schema operation admitted without authorization.');
    } catch (MigrationException $failure) {
        check(str_contains($failure->getMessage(), 'drop-all-tables'), 'Unexpected migration guard failure.');
    }
    check($db->table('lab_comp_parent')->where('tenant_id', '=', 1)->delete() === 1, 'Parent delete failed.');
    check($peer->table('lab_comp_child')->count() === 1 && (int) $peer->table('lab_comp_child')->value('id') === 2, 'Composite delete cascade crossed tenant.');
    echo "Composite primary/foreign key enforcement, native cascades and destructive guard passed\n";
}

function databaseRightJoinContracts(Connection $db): void
{
    seedRows($db, 20);
    $db->statement('CREATE TABLE lab_right_target (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
    $db->table('lab_right_target')->insert([['id' => 2, 'tenant_id' => 1], ['id' => 4, 'tenant_id' => 1],
        ['id' => 24, 'tenant_id' => 1], ['id' => 3, 'tenant_id' => 2]]);
    $project = static fn(array $rows): array => array_map(static fn(array $row): array => [
        (int) $row['target_id'], $row['record_id'] === null ? null : (int) $row['record_id'],
    ], $rows);
    $base = $db->table('lab_records')->as('r')->addSelectAs('t.id', 'target_id')->addSelectAs('r.id', 'record_id');
    $direct = $base->cloneBuilder()->rightJoinAs('lab_right_target', 't', 'r.id', '=', 't.id')
        ->where('t.tenant_id', '=', 1)->orderBy('t.id');
    $targets = $db->table('lab_right_target')->select('id')->where('tenant_id', '=', 1);
    $derived = $base->cloneBuilder()->rightJoinSub($targets, 't', 'r.id', '=', 't.id')->orderBy('t.id');
    $expected = [[2, 2], [4, 4], [24, null]];
    check($project($direct->get()) === $expected && $project($derived->get()) === $expected,
        'Right outer join lost unmatched row or crossed tenant boundary.');
    check($targets->getBindings() === [1] && $base->getBindings() === [], 'Join mutated reusable operands.');
    $peer = new Connection(config());
    check($peer->table('lab_right_target')->count() === 4 && $peer->table('lab_records')->count() === 20, 'Read mutated durable records.');
    echo "Native aliased and derived right outer join, NULL preservation and binding ownership passed\n";
}

function databaseChainedCteContracts(Connection $db): void
{
    seedRows($db, 20);
    $first = $db->table('lab_records')->select('id', 'amount')->where('tenant_id', '=', 1);
    $second = $db->query()->from('tenant_records')->select('id', 'amount')->where('amount', '>=', 4);
    $query = $db->query()->with('tenant_records', $first)->with('eligible_records', $second)
        ->from('eligible_records')->select('id', 'amount')->where('amount', '<=', 6)->orderBy('id');
    $project = static fn(array $rows): array => array_map(static fn(array $row): array => [(int) $row['id'], (int) $row['amount']], $rows);
    check($project($query->get()) === [[4, 4], [6, 6], [14, 4], [16, 6]], 'Chained CTE produced incorrect bound rows.');
    check($query->getBindings() === [1, 4, 6] && $first->getBindings() === [1] && $second->getBindings() === [4],
        'Chained CTE binding order or ownership changed.');
    $branch = $query->cloneBuilder()->where('id', '>', 10);
    check($project($branch->get()) === [[14, 4], [16, 6]] && $query->getBindings() === [1, 4, 6], 'CTE clone contaminated original.');
    check($query->get() !== [] && (new Connection(config()))->table('lab_records')->count() === 20, 'CTE reuse or persisted state failed.');
    echo "Multiple named CTE dependencies, binding order and clone ownership passed\n";
}

function databaseBidirectionalCursorContracts(Connection $db): void
{
    seedRows($db, 30);
    $signed = new Connection(config(['security' => ['cursor_signing_key' => random_bytes(32)]]));
    $query = $signed->table('lab_records')->where('tenant_id', '=', 1)->orderByDesc('amount')->orderBy('id');
    $expected = range(2, 30, 2);
    usort($expected, static fn(int $a, int $b): int => ($b % 10 <=> $a % 10) ?: ($a <=> $b));
    $ids = static fn($page): array => array_map('intval', array_column($page->items(), 'id'));
    $pages = [];
    $cursor = null;
    for ($index = 0; $index < 4; ++$index) {
        $page = $query->cursorPaginate(4, $cursor);
        check($ids($page) === array_slice($expected, $index * 4, 4), 'Forward mixed-direction keyset traversal mismatch.');
        check($page->hasPreviousPage() === ($index > 0) && $page->hasMorePages() === ($index < 3), 'Cursor navigation metadata mismatch.');
        $pages[] = $page;
        $cursor = $page->nextCursor();
    }
    check($cursor === null, 'Cursor persisted after final page.');
    for ($index = 3; $index > 0; --$index) {
        $previous = $pages[$index]->previousCursor();
        check(is_string($previous), 'Missing previous cursor.');
        $page = $query->cursorPaginate(4, $previous);
        check($ids($page) === $ids($pages[$index - 1]), 'Reverse keyset traversal changed tied row order.');
    }
    $next = $pages[0]->nextCursor();
    check(is_string($next), 'Missing signed cursor.');
    rejected(static fn() => $signed->table('lab_records')->where('tenant_id', '=', 2)->orderByDesc('amount')->orderBy('id')->cursorPaginate(4, $next), 'Cursor accepted foreign tenant.');
    rejected(static fn() => $signed->table('lab_records')->where('tenant_id', '=', 1)->orderBy('amount')->orderBy('id')->cursorPaginate(4, $next), 'Cursor accepted changed ordering.');
    check($ids($query->cursorPaginate(4)) === $ids($pages[0]) && $query->getBindings() === [1], 'Cursor traversal mutated original query.');
    echo "Signed mixed-direction forward/reverse pagination, ties and query-bound replay rejection passed\n";
}

final class LabPruneRepository extends \Infocyph\DBLayer\Repository\TableRepository
{
    protected static string $table = 'lab_records';

    protected static function globalScopes(): array
    {
        return ['tenant' => static fn(\Infocyph\DBLayer\Query\QueryBuilder $query) => $query->where('tenant_id', '=', 1)];
    }
}

function databaseBoundedPrunerContracts(Connection $db): void
{
    seedRows($db, 40);
    \Infocyph\DBLayer\DB::addConnection(config(), 'lab-pruner');
    $pruner = LabPruneRepository::pruner('lab-pruner');
    $scope = static fn(\Infocyph\DBLayer\Repository\RepositoryQuery $query) => $query->where('amount', '<', 6);
    check($pruner->count($scope) === 12, 'Prune scope count crossed tenant or threshold.');
    try {
        $pruner->prune($scope, chunkSize: 0, force: true);
        throw new RuntimeException('Zero-size prune accepted.');
    } catch (InvalidArgumentException) {
        check($db->table('lab_records')->count() === 40, 'Invalid prune changed durable rows.');
    }
    check($pruner->prune($scope, chunkSize: 3, force: true) === 12, 'Bounded pruning count mismatch.');
    $peer = new Connection(config());
    $remaining = array_map('intval', $peer->table('lab_records')->orderBy('id')->pluck('id'));
    $expected = array_values(array_filter(range(1, 40), static fn(int $id): bool => $id % 2 !== 0 || $id % 10 >= 6));
    check($remaining === $expected && $pruner->count($scope) === 0, 'Pruning removed unrelated rows or skipped candidates.');
    check($pruner->prune($scope, chunkSize: 3, force: true) === 0 && $peer->table('lab_records')->count() === 28, 'Empty retry changed durable rows.');
    echo "Repository-scoped bounded force pruning, invalid size and idempotent retry passed\n";
}
