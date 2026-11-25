<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\DBException;
use Infocyph\DBLayer\Exceptions\TransactionException;
use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Query\ConnectionRepository;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

function rejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (DBException) {
        return;
    }

    throw new RuntimeException($message);
}

function schemaWorkload(Connection $db): void
{
    $schema = new SchemaManager($db);
    $migration = new class implements Migration {
        public function id(): string
        {
            return '202611250001_create_lab_customers';
        }

        public function up(SchemaManager $schema, MigrationContext $context): void
        {
            $schema->create('lab_customers', static function (Blueprint $table): void {
                $table->id();
                $table->string('email', 160)->unique();
                $table->decimal('credit', 12, 2)->default(0);
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
            $schema->create('lab_purchases', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id');
                $table->integer('amount');
                $table->foreign('customer_id')->references('id')->on('lab_customers')->onDelete('cascade');
                $table->index(['customer_id', 'amount']);
            });
        }

        public function down(SchemaManager $schema, MigrationContext $context): void
        {
            $schema->drop('lab_purchases');
            $schema->drop('lab_customers');
        }
    };
    if ($db->getDriverName() === 'sqlite') {
        $db->getPdo()->exec('PRAGMA foreign_keys = ON');
    }
    $runner = new MigrationRunner($db, [$migration]);
    $runner->run();
    check($schema->hasTable('lab_customers') && $schema->hasColumn('lab_customers', 'metadata'), 'Migration schema missing.');
    check($runner->run() === [], 'Migration executed twice.');
    check(($runner->status()[0]['applied'] ?? false) === true, 'Migration ledger missing.');
    $id = $db->table('lab_customers')->insertGetId(['email' => 'test@example.test', 'metadata' => '{"active":true}']);
    check((float) $db->table('lab_customers')->where('id', '=', $id)->value('credit') === 0.0, 'DDL default not applied.');
    rejected(static fn() => $db->table('lab_customers')->insert(['email' => 'test@example.test']), 'Unique constraint failed.');
    rejected(static fn() => $db->table('lab_purchases')->insert(['customer_id' => 999999, 'amount' => 1]), 'Foreign key accepted orphan.');
    $db->table('lab_purchases')->insert(['customer_id' => $id, 'amount' => 12]);
    $schema->table('lab_customers', static function (Blueprint $table): void {
        $table->string('display_name')->nullable();
    });
    check($schema->hasColumn('lab_customers', 'display_name'), 'ALTER TABLE failed.');
    $db->table('lab_customers')->where('id', '=', $id)->delete();
    check($db->table('lab_purchases')->count() === 0, 'Cascading foreign key did not reconcile.');
    $runner->rollback();
    check(!$schema->hasTable('lab_customers') && !$schema->hasTable('lab_purchases'), 'Migration rollback did not remove schema.');
    check(($runner->status()[0]['applied'] ?? true) === false, 'Rollback ledger not reconciled.');
    $runner->run();
    check($schema->hasTable('lab_customers'), 'Reapplication failed.');
    echo "Schema, constraints, migration idempotency, rollback and reapply passed\n";
}

function seedRows(Connection $db, int $count): void
{
    $db->statement('CREATE TABLE lab_records (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, amount INTEGER NOT NULL, version INTEGER NOT NULL DEFAULT 1, deleted_at VARCHAR(40) NULL, label VARCHAR(120) NOT NULL)');
    for ($start = 1; $start <= $count; $start += 200) {
        $batch = [];
        for ($id = $start; $id < min($start + 200, $count + 1); ++$id) {
            $batch[] = ['id' => $id, 'tenant_id' => ($id % 2) + 1, 'amount' => $id % 10, 'label' => 'record-' . $id];
        }
        $db->table('lab_records')->insert($batch);
    }
}

function queryWorkload(Connection $db): void
{
    seedRows($db, 200);
    $base = $db->table('lab_records')->where('tenant_id', '=', 1);
    check($base->cloneBuilder()->whereBetween('amount', [2, 6])->count() === 60, 'Range query mismatch.');
    check($base->count() === 100, 'Builder branch mutated base.');
    check($base->cloneBuilder()->whereIn('amount', [0, 2])->count() === 40, 'IN mismatch.');
    check($base->cloneBuilder()->whereNested(static fn($q) => $q->where('amount', '=', 0)->orWhere('amount', '=', 8))->count() === 40, 'Nested OR mismatch.');
    $db->statement('CREATE TABLE lab_tenants (id INTEGER PRIMARY KEY, name VARCHAR(40) NOT NULL)');
    $db->table('lab_tenants')->insert([['id' => 1, 'name' => 'one'], ['id' => 2, 'name' => 'two']]);
    $totals = $db->table('lab_records')->select('tenant_id')->selectRaw('SUM(amount) AS total')->groupBy('tenant_id');
    $joined = $db->table('lab_tenants')->as('t')->joinSub($totals, 'totals', 't.id', '=', 'totals.tenant_id')->select('t.name', 'totals.total')->orderBy('t.id')->get();
    check(count($joined) === 2 && (int) $joined[0]['total'] === 400 && (int) $joined[1]['total'] === 500, 'Derived join aggregate mismatch.');
    $cte = $db->table('lab_records')->with('large_records', static fn($q) => $q->from('lab_records')->select('id')->where('amount', '>=', 8))->from('large_records')->get();
    check(count($cte) === 40, 'CTE result mismatch.');
    $union = $db->table('lab_records')->select('id')->where('id', '=', 1)->unionAll($db->table('lab_records')->select('id')->where('id', '=', 200))->get();
    $ids = array_map('intval', array_column($union, 'id'));
    sort($ids);
    check($ids === [1, 200], 'UNION ALL mismatch.');
    $db->table('lab_records')->upsert([
        ['id' => 1, 'tenant_id' => 2, 'amount' => 22, 'label' => 'updated'],
        ['id' => 201, 'tenant_id' => 1, 'amount' => 11, 'label' => 'inserted'],
    ], ['id'], ['amount', 'label']);
    check($db->table('lab_records')->count() === 201 && (int) $db->table('lab_records')->where('id', '=', 1)->value('amount') === 22, 'Upsert mismatch.');
    $db->table('lab_records')->insertIgnore(['id' => 1, 'tenant_id' => 1, 'amount' => 99, 'label' => 'ignore']);
    check((int) $db->table('lab_records')->where('id', '=', 1)->value('amount') === 22, 'Insert-ignore mutated existing row.');
    check($db->table('lab_records')->where('id', '=', 1)->getBindings() === [1], 'Bindings mismatch.');
    echo "Builder composition, aggregates, CTE, union, upsert and conflict handling passed\n";
}

function repositoryWorkload(Connection $db): void
{
    seedRows($db, 20);
    $tenant = new class($db) extends ConnectionRepository {
        protected function table(): string
        {
            return 'lab_records';
        }
    };
    $tenant->forTenant(1)->setCasts(['id' => 'int', 'tenant_id' => 'int', 'amount' => 'int', 'version' => 'int'])->enableSoftDeletes()->enableOptimisticLocking();
    check($tenant->count() === 10 && $tenant->find(1) === null, 'Tenant read leaked another tenant.');
    check($tenant->updateById(1, ['amount' => 999]) === 0, 'Tenant write crossed boundary.');
    $row = $tenant->find(2);
    check($row !== null && $row['amount'] === 2 && $row['version'] === 1, 'Repository casts failed.');
    check($tenant->updateByIdWithVersion(2, ['amount' => 42], 1), 'Optimistic update failed.');
    check(!$tenant->updateByIdWithVersion(2, ['amount' => 99], 1), 'Stale version update succeeded.');
    check($tenant->find(2)['amount'] === 42 && $tenant->find(2)['version'] === 2, 'Optimistic write reconciliation failed.');
    $tenant->deleteById(2);
    check($tenant->find(2) === null && $tenant->count() === 9, 'Soft delete visibility failure.');
    check($tenant->onlyTrashed()->count() === 1, 'Trashed scope mismatch.');
    $tenant->restoreById(2);
    check($tenant->withoutTrashed()->count() === 10, 'Restore failed.');
    check($tenant->forceDeleteById(2) === 1 && $tenant->count() === 9, 'Force delete failed.');
    check($db->table('lab_records')->count() === 19, 'Repository effects not reconciled.');
    echo "Tenant repository boundaries, casts, version CAS, soft delete and restore passed\n";
}

function cacheWorkload(Connection $db): void
{
    seedRows($db, 10);
    $cacheDir = (string) getenv('EVIDENCE') . '/query-cache';
    $cache = Cache::file('db-query', $cacheDir);
    $db->setQueryCache($cache);
    $peer = new Connection(config());
    $peer->setQueryCache($cache);
    $cached = static fn(Connection $conn): int => (int) $conn->table('lab_records')->where('id', '=', 2)->cacheFor(120)->value('amount');
    check($cached($db) === 2 && $cached($peer) === 2, 'Initial cache population failed.');
    // Raw write deliberately does not infer dependency tags.
    $peer->update('UPDATE lab_records SET amount = 3 WHERE id = 2');
    check($cached($db) === 2, 'Cache hit was not independently demonstrated.');
    try {
        $peer->transaction(static function (Connection $conn) use ($cached): void {
            $conn->table('lab_records')->where('id', '=', 2)->update(['amount' => 9]);
            check($cached($conn) === 9, 'Transaction failed to bypass cache.');
            throw new DomainException('Intentional rollback');
        });
        throw new RuntimeException('Expected rollback did not occur.');
    } catch (TransactionException $e) {
        check($e->getPrevious() instanceof DomainException, 'Unexpected transaction failure.');
    }
    check($cached($db) === 2, 'Rollback invalidated cache.');
    check((int) $db->table('lab_records')->where('id', '=', 2)->value('amount') === 3, 'Rollback durable data mismatch.');
    $peer->transaction(static function (Connection $conn): void {
        $conn->table('lab_records')->where('id', '=', 2)->update(['amount' => 7]);
    });
    check($cached($db) === 7, 'Shared cache invalidation after commit failed.');
    echo "Cache hit, transaction bypass, rollback preservation and shared commit invalidation passed\n";
}

function streamWorkload(Connection $db): void
{
    seedRows($db, 10000);
    $count = 0;
    $sum = 0;
    $started = hrtime(true);
    $before = memory_get_usage(true);
    foreach ($db->table('lab_records')->orderBy('id')->unbufferedStream(fetchSize: 128) as $row) {
        ++$count;
        $sum += (int) $row['id'];
        check((int) $row['id'] === $count, 'Stream row gap or reorder.');
    }
    check($count === 10000 && $sum === 50005000, 'Stream reconciliation failed.');
    $cursor = null;
    $seen = [];
    do {
        $page = $db->table('lab_records')->where('tenant_id', '=', 1)->orderBy('amount')->cursorPaginate(127, $cursor, 'id');
        foreach ($page->items() as $row) {
            $id = (int) $row['id'];
            check(!isset($seen[$id]) && $id % 2 === 0, 'Cursor duplicate or tenant mismatch.');
            $seen[$id] = true;
        }
        $cursor = $page->nextCursor();
    } while ($cursor !== null && count($seen) <= 5000);
    check(count($seen) === 5000, 'Composite keyset traversal missed rows.');
    $iter = $db->table('lab_records')->orderBy('id')->unbufferedStream(fetchSize: 128);
    foreach ($iter as $row) {
        check((int) $row['id'] === 1, 'Early iterator did not start correctly.');
        break;
    }
    unset($iter);
    check($db->table('lab_records')->count() === 10000, 'Connection not reusable after early iterator close.');
    echo json_encode(['rows' => $count, 'cursor_rows' => count($seen), 'milliseconds' => (hrtime(true) - $started) / 1e6, 'php_allocated_delta' => memory_get_usage(true) - $before, 'php_peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR), PHP_EOL;
}

function securityWorkload(Connection $db): void
{
    seedRows($db, 20);
    $payload = "record-1' OR '1'='1";
    check($db->table('lab_records')->where('label', '=', $payload)->count() === 0, 'Bound payload altered SQL semantics.');
    check($db->table('lab_records')->count() === 20, 'Bound payload altered data.');
    $secured = new Connection(config(['security' => ['raw_sql_policy' => 'deny', 'strict_identifiers' => true, 'cursor_signing_key' => random_bytes(32)]]));
    rejected(static fn() => $secured->table('lab_records')->whereRaw('id = ?', [1])->get(), 'Raw fragment deny policy bypassed.');
    rejected(static fn() => $secured->table('lab_records')->orderBy('id; DROP TABLE lab_records')->get(), 'Unsafe identifier accepted.');
    $page = $secured->table('lab_records')->where('tenant_id', '=', 1)->orderBy('amount')->cursorPaginate(3);
    $cursor = $page->nextCursor();
    check(is_string($cursor) && $cursor !== '', 'Signed cursor missing.');
    rejected(static fn() => $secured->table('lab_records')->where('tenant_id', '=', 2)->orderBy('amount')->cursorPaginate(3, $cursor), 'Cursor reused across tenant filters.');
    $tampered = ($cursor[0] === 'A' ? 'B' : 'A') . substr($cursor, 1);
    rejected(static fn() => $secured->table('lab_records')->where('tenant_id', '=', 1)->orderBy('amount')->cursorPaginate(3, $tampered), 'Tampered cursor accepted.');
    check($secured->table('lab_records')->count() === 20, 'Security checks altered data.');
    echo "Binding safety, raw-fragment policy, identifiers and signed cursor isolation passed\n";
}
