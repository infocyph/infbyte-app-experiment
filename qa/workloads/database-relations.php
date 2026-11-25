<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Repository\Relation;
use Infocyph\DBLayer\Repository\RelationLoader;
use Infocyph\DBLayer\Repository\TableRepository;

final class LabCustomerRepository extends TableRepository
{
    protected static string $table = 'lab_customers';

    protected static function relations(): array
    {
        $orders = Relation::hasMany(LabOrderRepository::class, 'customer_id');

        return [
            'orders' => $orders,
            'latest_order' => Relation::hasMany(LabOrderRepository::class, 'customer_id')->latestOfMany(),
        ];
    }
}

final class LabOrderRepository extends TableRepository
{
    protected static string $table = 'lab_purchases';

    protected static function relations(): array
    {
        return ['customer' => Relation::belongsTo(LabCustomerRepository::class, 'customer_id')];
    }
}

function relationsWorkload(Connection $db): void
{
    $db->statement('CREATE TABLE lab_customers (id INTEGER PRIMARY KEY, name VARCHAR(100) NOT NULL)');
    $db->statement('CREATE TABLE lab_purchases (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL, amount INTEGER NOT NULL)');
    $db->statement('CREATE TABLE lab_roles (id INTEGER PRIMARY KEY, name VARCHAR(40) NOT NULL)');
    $db->statement('CREATE TABLE lab_customer_roles (customer_id INTEGER NOT NULL, role_id INTEGER NOT NULL, PRIMARY KEY (customer_id, role_id))');
    $customers = [];
    $orders = [];
    $pivot = [];
    for ($id = 1; $id <= 60; ++$id) {
        $customers[] = ['id' => $id, 'name' => 'customer-' . $id];
        if ($id <= 50) {
            $orders[] = ['id' => $id * 2 - 1, 'customer_id' => $id, 'amount' => 10];
            $orders[] = ['id' => $id * 2, 'customer_id' => $id, 'amount' => 20];
            $pivot[] = ['customer_id' => $id, 'role_id' => 1];
            $pivot[] = ['customer_id' => $id, 'role_id' => 2];
        }
    }
    $db->table('lab_customers')->insert($customers);
    $db->table('lab_purchases')->insert($orders);
    $db->table('lab_roles')->insert([['id' => 1, 'name' => 'buyer'], ['id' => 2, 'name' => 'member']]);
    $db->table('lab_customer_roles')->insert($pivot);
    $parents = $db->table('lab_customers')->orderBy('id')->get();
    $parents[] = $parents[0];
    $loader = new RelationLoader($db, batchSize: 10);
    $many = $loader->many($parents, 'id', 'lab_purchases', 'customer_id', 'orders', ['id', 'amount'], static fn(QueryBuilder $q) => $q->orderBy('id'));
    check(count($many) === 61 && count($many[0]['orders']) === 2 && $many[50]['orders'] === [] && $many[60]['orders'] === $many[0]['orders'], 'Many projection failed.');
    check($loader->lastQueryCount() === 6 && $loader->lastRelatedRowCount() === 100, 'Many relation query bounds violated.');
    $one = $loader->one($parents, 'id', 'lab_purchases', 'customer_id', 'latest', ['id', 'amount'], static fn(QueryBuilder $q) => $q->orderByDesc('id'));
    check((int) $one[0]['latest']['id'] === 2 && $one[50]['latest'] === null, 'Deterministic one projection failed.');
    check($loader->lastQueryCount() === 6, 'One relation N+1 regression.');
    $roles = $loader->manyToMany($parents, 'id', 'lab_customer_roles', 'customer_id', 'role_id', 'lab_roles', 'id', 'roles', ['name']);
    check(count($roles[0]['roles']) === 2 && $roles[50]['roles'] === [] && $roles[60]['roles'] === $roles[0]['roles'], 'Pivot projection failed.');
    check($loader->lastQueryCount() === 7 && $loader->lastRelatedRowCount() === 102, 'Pivot relation query bounds violated.');
    check($loader->many([], 'id', 'lab_purchases', 'customer_id', 'orders') === [] && $loader->lastQueryCount() === 0, 'Empty relation issued SQL.');
    // The TableRepository facade is intentional here: declared repository relations are its public API.
    DB::addConnection(config(), 'lab-relations');
    DB::setDefaultConnection('lab-relations');
    $projected = LabCustomerRepository::query()->with('orders.customer')->with('latest_order')->withCount('orders')->get();
    $first = null;
    foreach ($projected as $row) {
        if ((int) $row['id'] === 1) {
            $first = $row;
            break;
        }
    }
    check(is_array($first) && count($first['orders']) === 2 && (int) $first['orders'][0]['customer']['id'] === 1, 'Nested declared relation mismatch.');
    check((int) $first['latest_order']['id'] === 2 && (int) $first['orders_count'] === 2, 'One-of-many/count projection mismatch.');
    echo "Bounded eager projections, duplicate keys, pivot, nested repository and one-of-many passed\n";
}

function driverWorkload(Connection $db): void
{
    $driver = $db->getDriverName();
    $expectedReturning = in_array($driver, ['mariadb', 'pgsql', 'mssql'], true);
    $expectedIgnore = in_array($driver, ['mysql', 'mariadb', 'sqlite'], true);
    check($db->supportsReturning() === $expectedReturning && $db->supportsInsertIgnore() === $expectedIgnore, 'Driver capability mismatch.');
    check($db->supportsUpsert() && $db->supportsSavepoints() && $db->supportsJson() && $db->supportsWindowFunctions(), 'Documented driver capability absent.');
    check($db->supportsSchemas() === ($driver !== 'sqlite'), 'Schema capability mismatch.');
    seedRows($db, 20);
    $ranked = $db->table('lab_records')->select('id', 'tenant_id')->selectWindow('row_number()', 'row_rank', ['tenant_id'], ['id asc'])->orderBy('id')->get();
    check(count($ranked) === 20, 'Window row count mismatch.');
    foreach ($ranked as $row) {
        check((int) $row['row_rank'] === intdiv((int) $row['id'] + 1, 2), 'Partitioned rank mismatch.');
    }
    $db->table('lab_records')->upsert([
        ['id' => 1, 'tenant_id' => 2, 'amount' => 33, 'label' => 'updated'],
        ['id' => 21, 'tenant_id' => 2, 'amount' => 44, 'label' => 'new'],
    ], ['id'], ['amount', 'label']);
    check($db->table('lab_records')->count() === 21 && (int) $db->table('lab_records')->find(1)['amount'] === 33, 'Driver upsert mismatch.');
    $ignore = static fn() => $db->table('lab_records')->insertIgnore(['id' => 1, 'tenant_id' => 2, 'amount' => 99, 'label' => 'ignore']);
    if ($expectedIgnore) {
        $ignore();
    } else {
        rejected($ignore, 'Unsupported driver insert-ignore accepted.');
    }
    check((int) $db->table('lab_records')->find(1)['amount'] === 33, 'Conflict mutated existing row.');
    $returned = $db->table('lab_records')->upsertReturning([
        ['id' => 1, 'tenant_id' => 2, 'amount' => 55, 'label' => 'return'],
    ], ['id'], ['amount', 'label'], ['id', 'amount']);
    check(count($returned) === 1 && (int) $returned[0]['id'] === 1 && (int) $returned[0]['amount'] === 55, 'Native or documented returning fallback mismatch.');
    $db->transaction(static function (Connection $conn): void {
        $conn->table('lab_records')->where('id', '=', 1)->update(['amount' => 66]);
        $conn->beginTransaction();
        $conn->table('lab_records')->where('id', '=', 1)->update(['amount' => 77]);
        $conn->rollbackTransaction();
        check((int) $conn->table('lab_records')->find(1)['amount'] === 66, 'Driver savepoint rollback mismatch.');
    });
    check((int) $db->table('lab_records')->find(1)['amount'] === 66, 'Driver commit mismatch.');
    echo json_encode(['driver' => $driver, 'returning' => $expectedReturning, 'insert_ignore' => $expectedIgnore, 'rows' => 21], JSON_THROW_ON_ERROR), PHP_EOL;
}
