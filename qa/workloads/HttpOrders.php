<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Filesystem\StorageRegistry;
use Infocyph\Webrick\Response\Response;

final readonly class HttpOrders
{
    public function __construct(private Application $app) {}

    /** @throws \Throwable */
    public static function seed(Application $app): void
    {
        new Accounts($app)->setup();
        $auth = $app->make(AuthServices::class);
        $permission = $auth->permissions()->create('orders.manage');
        $directory = $app->storagePath('lab-private');
        ensure(mkdir($directory, 0700, true), 'Unable to allocate private test directory.');
        foreach (['buyer', 'foreign'] as $identity) {
            $account = $auth->accounts()->create($identity . '@example.test', metadata: ['tenant' => $identity])->account;
            ensure($account instanceof Account, 'Missing account.');
            if ($identity === 'buyer') {
                $auth->permissions()->assignToAccount($account->id(), $permission->id);
            }
            $now = time();
            $issued = $auth->tokens()->issueAccessToken(new AccessTokenClaims($account->id(), null, $now, $now + 1800, ['orders.manage']));
            ensure(is_string($issued->token) && $issued->token !== '', 'JWT issuance failed.');
            ensure(file_put_contents($directory . '/' . $identity, $issued->token, LOCK_EX) !== false, 'Token fixture write failed.');
            chmod($directory . '/' . $identity, 0600);
        }
    }

    /** @throws \Throwable */
    public function create(string $id): Response
    {
        if (!$this->permitted()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        if (preg_match('/^[a-z0-9-]{1,60}$/D', $id) !== 1) {
            return Response::json(['error' => 'invalid'], 422);
        }
        new Orders($this->app)->execute($id);

        return Response::json(['id' => $id, 'amount' => 300], 201);
    }

    /** @throws \Throwable */
    public function read(string $id): Response
    {
        if (!$this->permitted()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $amount = $db->scalar('SELECT amount FROM experiment_orders WHERE id = ?', [$id]);
        if (!is_numeric($amount)) {
            return Response::json(['error' => 'not found'], 404);
        }
        $document = $this->app->make(StorageRegistry::class)->disk('local')->read('experiment/orders/' . $id . '.json');
        $receipt = json_decode($document, true, flags: JSON_THROW_ON_ERROR);
        ensure(($receipt['order'] ?? null) === $id && ($receipt['amount'] ?? null) === (int) $amount, 'Receipt/database mismatch.');

        return Response::json(['id' => $id, 'amount' => (int) $amount]);
    }

    /** @throws \Throwable */
    private function permitted(): bool
    {
        $services = $this->app->make(AuthServices::class);
        $principal = $services->principals()->get();

        return $principal !== null && $services->authorizer()->can($principal, 'orders.manage')->allowed;
    }
}
