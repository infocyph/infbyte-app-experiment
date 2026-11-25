<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Filesystem\StorageRegistry;
use Infocyph\UID\Id;
use Infocyph\Webrick\Response\Response;

final readonly class TenantOrders
{
    public function __construct(private Application $app) {}

    public function setup(): void
    {
        new Accounts($this->app)->setup();
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $db->statement('CREATE TABLE lab_tenant_members (account_id VARCHAR(128) PRIMARY KEY, tenant VARCHAR(32) NOT NULL)');
        $db->statement('CREATE TABLE lab_tenant_stock (tenant VARCHAR(32) PRIMARY KEY, quantity INTEGER NOT NULL)');
        $db->statement('CREATE TABLE lab_tenant_orders (id VARCHAR(80) PRIMARY KEY, tenant VARCHAR(32) NOT NULL, request_id VARCHAR(80) NOT NULL, UNIQUE (tenant, request_id))');
        $auth = $this->app->make(AuthServices::class);
        $permission = $auth->permissions()->create('orders.manage');
        $directory = $this->app->storagePath('lab-private');
        ensure(mkdir($directory, 0700, true), 'Private token directory failed.');
        foreach (['alpha', 'beta', 'denied'] as $tenant) {
            $account = $auth->accounts()->create($tenant . '@example.test')->account;
            ensure($account instanceof Account, 'Account creation failed.');
            $db->statement('INSERT INTO lab_tenant_members (account_id, tenant) VALUES (?, ?)', [$account->id(), $tenant]);
            if ($tenant !== 'denied') {
                $auth->permissions()->assignToAccount($account->id(), $permission->id);
                $db->statement('INSERT INTO lab_tenant_stock (tenant, quantity) VALUES (?, ?)', [$tenant, 10000]);
            }
            $now = time();
            $issued = $auth->tokens()->issueAccessToken(new AccessTokenClaims($account->id(), null, $now, $now + 1800, ['orders.manage']));
            ensure(is_string($issued->token) && $issued->token !== '', 'Token issuance failed.');
            ensure(file_put_contents($directory . '/' . $tenant, $issued->token, LOCK_EX) !== false, 'Token fixture write failed.');
            chmod($directory . '/' . $tenant, 0600);
        }
    }

    public function checkout(string $tenant, string $request): Response
    {
        if (!$this->authorized($tenant)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        if (preg_match('/^[a-z0-9-]{1,60}$/D', $request) !== 1) {
            return Response::json(['error' => 'invalid'], 422);
        }
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $order = $db->transaction(static function () use ($db, $tenant, $request): array {
            $stock = $db->select('SELECT quantity FROM lab_tenant_stock WHERE tenant = ? FOR UPDATE', [$tenant]);
            $existing = $db->select('SELECT id FROM lab_tenant_orders WHERE tenant = ? AND request_id = ?', [$tenant, $request]);
            if ($existing !== []) {
                return ['id' => $existing[0]['id'], 'created' => false];
            }
            ensure((int) $stock[0]['quantity'] >= 2, 'Stock exhausted.');
            $id = Id::uuid7();
            $db->statement('UPDATE lab_tenant_stock SET quantity = quantity - 2 WHERE tenant = ?', [$tenant]);
            $db->statement('INSERT INTO lab_tenant_orders (id, tenant, request_id) VALUES (?, ?, ?)', [$id, $tenant, $request]);

            return ['id' => $id, 'created' => true];
        });
        $payload = json_encode(['id' => $order['id'], 'tenant' => $tenant], JSON_THROW_ON_ERROR);
        $disk = $this->app->make(StorageRegistry::class)->disk('local');
        $disk->write('experiment/tenants/' . $tenant . '/' . $order['id'] . '.json', $payload);
        $cache = $this->app->make(CacheManager::class)->store('shared');
        ensure($cache->set('tenant.' . $tenant . '.' . $order['id'], $payload, 3600), 'Tenant cache write failed.');

        return Response::json($order, $order['created'] ? 201 : 200);
    }

    public function read(string $tenant, string $id): Response
    {
        if (!$this->authorized($tenant)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $db = $this->app->make(DBLayerFactory::class)->connection();
        if ($db->select('SELECT id FROM lab_tenant_orders WHERE tenant = ? AND id = ?', [$tenant, $id]) === []) {
            return Response::json(['error' => 'not found'], 404);
        }
        $disk = $this->app->make(StorageRegistry::class)->disk('local');
        $payload = $disk->read('experiment/tenants/' . $tenant . '/' . $id . '.json');
        $cache = $this->app->make(CacheManager::class)->store('shared');
        ensure($cache->get('tenant.' . $tenant . '.' . $id) === $payload, 'Cache/document mismatch.');
        return Response::json(json_decode($payload, true, flags: JSON_THROW_ON_ERROR));
    }

    public function verify(int $perTenant): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM lab_tenant_orders')) === 2 * $perTenant, 'Total order count mismatch.');
        foreach (['alpha', 'beta'] as $tenant) {
            ensure(countValue($db->scalar('SELECT COUNT(*) FROM lab_tenant_orders WHERE tenant = ?', [$tenant])) === $perTenant, 'Tenant order count mismatch.');
            ensure(countValue($db->scalar('SELECT quantity FROM lab_tenant_stock WHERE tenant = ?', [$tenant])) === 10000 - 2 * $perTenant, 'Tenant stock conservation failed.');
        }
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM lab_tenant_orders a JOIN lab_tenant_orders b ON a.request_id = b.request_id WHERE a.tenant = ? AND b.tenant = ?', ['alpha', 'beta'])) === $perTenant, 'Equal idempotency keys were not isolated.');
    }

    private function authorized(string $tenant): bool
    {
        $auth = $this->app->make(AuthServices::class);
        $principal = $auth->principals()->get();
        if ($principal === null || $principal->accountId() === null || !$auth->authorizer()->can($principal, 'orders.manage')->allowed) {
            return false;
        }
        $db = $this->app->make(DBLayerFactory::class)->connection();

        return $db->scalar('SELECT tenant FROM lab_tenant_members WHERE account_id = ?', [$principal->accountId()]) === $tenant;
    }
}
