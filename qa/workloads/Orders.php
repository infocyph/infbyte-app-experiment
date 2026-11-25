<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Filesystem\StorageRegistry;
use Infocyph\Foundation\Validation\ValidatorFactory;

final readonly class Orders
{
    public function __construct(private Application $app) {}

    /** @throws \Throwable */
    public function setup(): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $db->statement('CREATE TABLE experiment_stock (id INTEGER PRIMARY KEY, quantity INTEGER NOT NULL)');
        $db->statement('CREATE TABLE experiment_orders (id VARCHAR(80) PRIMARY KEY, tenant_id INTEGER NOT NULL, amount INTEGER NOT NULL)');
        $db->statement('CREATE TABLE experiment_lines (order_id VARCHAR(80) NOT NULL, sku INTEGER NOT NULL, quantity INTEGER NOT NULL, PRIMARY KEY (order_id, sku), FOREIGN KEY (order_id) REFERENCES experiment_orders(id))');
        $db->statement('CREATE TABLE experiment_audit (order_id VARCHAR(80) PRIMARY KEY, action VARCHAR(32) NOT NULL, FOREIGN KEY (order_id) REFERENCES experiment_orders(id))');
        for ($sku = 1; $sku <= 4; ++$sku) {
            $db->statement('INSERT INTO experiment_stock (id, quantity) VALUES (?, ?)', [$sku, 100000]);
        }
    }

    /** @throws \Throwable */
    public function execute(string $id): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $validator = $this->app->make(ValidatorFactory::class)->make('orders.create');
        ensure($validator->validate(['sku' => 1, 'quantity' => 2])->passes(), 'Valid order rejected.');
        ensure($validator->validate(['sku' => 999, 'quantity' => 0])->fails(), 'Invalid order accepted.');
        $db->transaction(function () use ($db, $id): void {
            $db->statement('INSERT INTO experiment_orders (id, tenant_id, amount) VALUES (?, ?, ?)', [$id, 1, 300]);
            // Consistent lock order across workers; all orders contend for the same inventory.
            foreach ([1, 2] as $sku) {
                $db->statement('UPDATE experiment_stock SET quantity = quantity - ? WHERE id = ? AND quantity >= ?', [2, $sku, 2]);
                $db->statement('INSERT INTO experiment_lines (order_id, sku, quantity) VALUES (?, ?, ?)', [$id, $sku, 2]);
            }
            $db->statement('INSERT INTO experiment_audit (order_id, action) VALUES (?, ?)', [$id, 'placed']);
        });
        try {
            $db->transaction(static function () use ($db, $id): never {
                $db->statement('UPDATE experiment_stock SET quantity = quantity - 100 WHERE id = 1');
                $db->statement('INSERT INTO experiment_orders (id, tenant_id, amount) VALUES (?, ?, ?)', [$id . '-rollback', 1, 999]);
                throw new \DomainException('intentional rollback');
            });
        } catch (\DomainException $failure) {
            ensure($failure->getMessage() === 'intentional rollback', 'Unexpected rollback exception.');
        }
        $cache = $this->app->make(CacheManager::class)->store('shared');
        $atomic = $cache->atomic();
        ensure($atomic !== null, 'Shared Redis lacks atomic capability.');
        ensure($atomic->setIfAbsent('order:' . $id, 1, 3600), 'Duplicate business operation.');
        ensure(!$atomic->setIfAbsent('order:' . $id, 2, 3600), 'Replay accepted.');
        $disk = $this->app->make(StorageRegistry::class)->disk('local');
        $payload = json_encode(['order' => $id, 'amount' => 300], JSON_THROW_ON_ERROR);
        $path = 'experiment/orders/' . $id . '.json';
        $disk->write($path, $payload);
        ensure($disk->read($path) === $payload, 'Order document corrupted.');
    }

    /** @throws \Throwable */
    public function verify(int $expected): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM experiment_orders')) === $expected, 'Order count mismatch.');
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM experiment_lines')) === $expected * 2, 'Missing order lines.');
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM experiment_audit')) === $expected, 'Missing audit records.');
        ensure(countValue($db->scalar('SELECT SUM(amount) FROM experiment_orders')) === $expected * 300, 'Ledger total mismatch.');
        ensure(countValue($db->scalar('SELECT SUM(quantity) FROM experiment_stock')) === 400000 - $expected * 4, 'Inventory conservation failed.');
        ensure(countValue($db->scalar("SELECT COUNT(*) FROM experiment_orders WHERE id LIKE '%-rollback'")) === 0, 'Rollback leaked.');
    }
}
