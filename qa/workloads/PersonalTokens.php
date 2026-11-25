<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenManager;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class PersonalTokens
{
    public function __construct(private Application $app) {}

    public function setup(): void
    {
        $schema = $this->app->make(AuthSchemaInstaller::class);
        $schema->install();
        ensure($schema->installed(), 'PAT schema not ready.');
        $this->app->make(DBLayerFactory::class)->connection()->statement('CREATE TABLE experiment_pat (id VARCHAR(80) PRIMARY KEY)');
    }

    public function execute(string $id): void
    {
        $account = $this->app->make(AuthServices::class)->accounts()->create('pat-' . $id . '@example.test')->account;
        ensure($account instanceof Account, 'PAT account missing.');
        $subject = $account->id();
        $tokens = $this->app->make(PersonalAccessTokenManager::class);
        $first = $tokens->issue($subject, 'automation', ['orders.read']);
        $verified = $tokens->verify($first->token);
        ensure($verified->accepted() && $verified->allows('orders.read') && !$verified->allows('orders.write'), 'PAT ability policy failed.');
        ensure(count($tokens->list($subject)) === 1, 'PAT listing mismatch.');
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $table = $this->app->make(AuthTables::class)->personalAccessTokens();
        $row = $db->select('SELECT * FROM ' . $table . ' WHERE token_id = ?', [$first->record->tokenId])[0] ?? null;
        ensure(is_array($row) && !str_contains(json_encode($row, JSON_THROW_ON_ERROR), $first->token), 'Raw PAT persisted.');
        $revoked = $tokens->revoke($first->record->tokenId, $subject);
        $again = $tokens->revoke($first->record->tokenId, $subject);
        ensure($revoked?->revokedAt !== null && $again?->revokedAt === $revoked->revokedAt && !$tokens->verify($first->token)->accepted(), 'PAT revoke failed.');
        $a = $tokens->issue($subject, 'before-a', ['orders.read']);
        $b = $tokens->issue($subject, 'before-b', ['orders.read']);
        ensure($tokens->revokeAll($subject) === 2 && $tokens->revokeAll($subject) === 0, 'PAT bulk revocation mismatch.');
        ensure(!$tokens->verify($a->token)->accepted() && !$tokens->verify($b->token)->accepted(), 'Bulk-revoked token accepted.');
        $after = $tokens->issue($subject, 'after', ['orders.read']);
        ensure($tokens->verify($after->token)->accepted(), 'PAT issuance after bulk revoke failed.');
        $db->statement('INSERT INTO experiment_pat (id) VALUES (?)', [$id]);
    }

    public function verify(int $expected): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM experiment_pat')) === $expected, 'PAT operation count mismatch.');
        $table = $this->app->make(AuthTables::class)->personalAccessTokens();
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM ' . $table)) === $expected * 4, 'PAT durable record count mismatch.');
    }
}
