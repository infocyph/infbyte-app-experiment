<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerEpicryptJwtReplayStore;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenStatus;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;

require __DIR__ . '/bootstrap.php';

$app = application();
$mode = $argv[1] ?? '';
$app->execution()->run(static function () use ($app, $mode): void {
    if ($mode === 'setup') {
        new Accounts($app)->setup();
        return;
    }
    if ($mode === 'replay-race' || $mode === 'replay-verify') {
        $store = new DBLayerEpicryptJwtReplayStore($app->container(), new AuthTables());
        $expiry = (int) getenv('LAB_REPLAY_EXPIRY');
        ensure($expiry > time(), 'Replay fixture expired.');
        if ($mode === 'replay-race') {
            echo (int) $store->consume('tenant-a', 'shared-token', $expiry), PHP_EOL;
            return;
        }
        ensure(!$store->consume('tenant-a', 'shared-token', $expiry) && $store->isRevoked('tenant-a', 'shared-token', $expiry), 'Durable replay state missing.');
        ensure(!$store->isRevoked('tenant-b', 'shared-token', $expiry), 'Replay namespace crossed boundary.');
        ensure($store->consume('tenant-b', 'shared-token', $expiry) && !$store->consume('tenant-b', 'shared-token', $expiry), 'Independent namespace failed.');
        echo "Durable replay state and tenant namespace reconciliation passed\n";
        return;
    }
    ensure($mode === 'lifecycle', 'Unknown authentication workload mode.');
    $auth = $app->make(AuthServices::class);
    $actions = $app->make(AuthActions::class);
    $password = 'Experiment-only-Passphrase!42';
    $identifier = 'lifecycle@example.test';
    $owner = $auth->accounts()->create($identifier, $auth->passwordHasher()->hash($password))->account;
    $other = $auth->accounts()->create('foreign@example.test')->account;
    ensure($owner instanceof Account && $other instanceof Account, 'Accounts unavailable.');
    $id = $owner->id();
    $login = static fn() => $actions->login(['identifier' => $identifier, 'password' => $password]);
    foreach ([
        'lock' => AccountStatus::LOCKED,
        'suspend' => AccountStatus::SUSPENDED,
        'disable' => AccountStatus::DISABLED,
    ] as $operation => $status) {
        $auth->accounts()->$operation($id);
        ensure($auth->accountProvider()->findById($id)?->status() === $status && !$login()->authenticated(), 'Inactive account authenticated.');
        $auth->accounts()->unlock($id);
        ensure($auth->accountProvider()->findById($id)?->status() === AccountStatus::ACTIVE, 'Account unlock failed.');
    }
    $auth->lockouts()->clearFailures($id);
    for ($attempt = 1; $attempt <= 5; ++$attempt) {
        $result = $auth->lockouts()->recordLoginFailure($id);
        if ($attempt < 5) {
            ensure($result->attempts === $attempt && !$auth->lockouts()->isLocked($id), 'Counter/threshold mismatch.');
        }
    }
    ensure($auth->lockouts()->isLocked($id) && !$login()->authenticated() && !$auth->lockouts()->isLocked($other->id()), 'Lockout was bypassed or crossed account.');
    $auth->lockouts()->unlock($id);
    ensure(!$auth->lockouts()->isLocked($id) && $login()->authenticated(), 'Unlock did not restore login.');
    $device = $auth->devices()->register($id, 'Laboratory', 'fixture-fingerprint')->device;
    ensure($device !== null && !$auth->devices()->isTrustedForAccount($id, $device->id), 'New device trusted prematurely.');
    ensure($auth->devices()->trust($device->id)->successful(), 'Device trust failed.');
    ensure($auth->devices()->isTrustedForAccount($id, $device->id) && !$auth->devices()->isTrustedForAccount($other->id(), $device->id), 'Trusted device crossed account.');
    $issued = $auth->rememberMe()->issue($id, $device->id);
    ensure($issued->token !== null && $issued->record !== null, 'Remember token issuance failed.');
    $verified = $auth->rememberMe()->verify($issued->token->value);
    ensure($verified->verified() && $verified->record?->accountId === $id && $verified->record->deviceId === $device->id, 'Remember token identity changed.');
    $rotated = $auth->rememberMe()->rotate($verified->record);
    ensure($rotated->status === RememberTokenStatus::ROTATED && $rotated->token !== null && $rotated->record?->familyId === $issued->record->familyId, 'Remember family rotation failed.');
    ensure($auth->rememberMe()->verify($rotated->token->value)->verified(), 'Replacement token rejected.');
    ensure($auth->rememberMe()->verify($issued->token->value)->status === RememberTokenStatus::REUSED, 'Rotated token replay was not detected.');
    ensure(!$auth->rememberMe()->verify($rotated->token->value)->verified(), 'Compromised family remained active.');
    ensure($auth->devices()->revoke($device->id)->successful() && !$auth->devices()->isTrustedForAccount($id, $device->id), 'Device revocation failed.');
    ensure($auth->devices()->touch($device->id)->failed() && $auth->devices()->trust($device->id)->failed(), 'Revoked device was restored by touch/trust.');
    ensure(count($auth->devices()->listForAccount($other->id())) === 0, 'Device listing crossed account.');
    echo "Account states, Redis lockout, durable device trust and remember-family compromise recovery passed\n";
});
