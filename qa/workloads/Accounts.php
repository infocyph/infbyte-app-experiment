<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Contract\Storage\SessionStoreInterface;
use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class Accounts
{
    public function __construct(private Application $app) {}

    /** @throws \Throwable */
    public function setup(): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        new MigrationRunner($db, [new AuthSchema(new AuthTables())])->run();
    }

    /** @throws \Throwable */
    public function execute(string $id): void
    {
        $auth = $this->app->make(AuthServices::class);
        $actions = $this->app->make(AuthActions::class);
        $hasher = $auth->passwordHasher();
        $email = $id . '@example.test';
        $password = 'Experiment-only-Passphrase!42';
        $account = $auth->accounts()->create($email, $hasher->hash($password), ['tenant' => 'experiment'], AccountStatus::PENDING_VERIFICATION)->account;
        ensure($account instanceof Account, 'Account creation failed.');
        $verification = $auth->emailVerification()->issue($account->id(), $email);
        ensure($verification->token !== null, 'Missing verification token.');
        ensure($auth->emailVerification()->verify($verification->token)->verified(), 'Verification failed.');
        ensure($actions->login(['identifier' => $email, 'password' => 'wrong-password'])->failed(), 'Invalid password accepted.');
        $login = $actions->login(['identifier' => $email, 'password' => $password]);
        ensure($login->authenticated() && $login->session !== null, 'Valid login rejected.');
        $session = $login->session;
        $rotated = $auth->sessions()->rotate($session->id);
        $store = $this->app->make(SessionStoreInterface::class);
        ensure($store->find($session->id) === null && $store->find($rotated->id) !== null, 'Session rotation failed.');
        $refresh = $auth->tokens()->issueRefreshToken($account->id(), 'experiment', 'device');
        $record = $refresh->refreshToken;
        ensure($record !== null && $refresh->token !== null, 'Refresh token issuance failed.');
        ensure($auth->tokens()->verifyRefreshToken($refresh->token)->successful(), 'Refresh token rejected.');
        ensure($auth->tokens()->rotateRefreshToken($record)->successful(), 'Refresh rotation failed.');
        ensure($auth->tokens()->rotateRefreshToken($record)->failed(), 'Refresh replay accepted.');
        $reset = $auth->passwordResets()->issue($account->id());
        ensure($reset->token !== null, 'Missing password reset token.');
        ensure($auth->passwordResets()->completeWithPlainPassword($reset->token, 'Experiment-new-Passphrase!43', $hasher, $auth->passwordPolicy())->completed(), 'Password reset failed.');
        ensure($auth->passwordResets()->complete($reset->token, $hasher->hash($password))->failed(), 'Password reset replay accepted.');
    }

    /** @throws \Throwable */
    public function verify(int $expected): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM auth_accounts')) === $expected, 'Persisted account count mismatch.');
    }
}
