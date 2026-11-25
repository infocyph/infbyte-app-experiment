<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Authentication\Login\Authenticator;
use Infocyph\Foundation\Auth\Authentication\Login\LoginRequest;
use Infocyph\Foundation\Auth\Authentication\Login\LoginResult;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Security\Risk\RiskAuthority;
use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Foundation\Security\Risk\RiskHashBulkhead;
use Infocyph\Foundation\Security\Risk\RiskLogin;
use Infocyph\Foundation\Security\Risk\RiskPolicy;
use Infocyph\Foundation\Security\Risk\RiskProofStore;
use Infocyph\Foundation\Security\Risk\RiskTelemetry;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\IpCidr;
use Infocyph\Webrick\Response\Response;

final readonly class Risk
{
    public function __construct(private Application $app) {}

    /** @throws \Throwable */
    public function setup(): void
    {
        new Accounts($this->app)->setup();
        $this->app->make(DBLayerFactory::class)->connection()->statement('CREATE TABLE experiment_risk (id VARCHAR(80) PRIMARY KEY)');
    }

    /** @throws \Throwable */
    public function execute(string $id): void
    {
        $cache = $this->app->make(CacheManager::class)->store('shared');
        $authorityKey = hex2bin((string) getenv('LAB_RISK_AUTHORITY_KEY'));
        $telemetryKey = hex2bin((string) getenv('LAB_RISK_TELEMETRY_KEY'));
        ensure(is_string($authorityKey) && is_string($telemetryKey), 'Missing isolated risk keys.');
        $authority = new RiskAuthority($cache, $authorityKey, shared: true);
        $telemetry = new RiskTelemetry($cache, $telemetryKey, shared: true);
        $proofs = new RiskProofStore($authority, 'lab-provider');
        $auth = $this->app->make(AuthServices::class);
        $authenticator = $this->app->make(Authenticator::class);
        $email = 'risk-' . $id . '@example.test';
        $password = 'Disposable-lab-password!72';
        ensure($auth->accounts()->create($email, $auth->passwordHasher()->hash($password))->account !== null, 'Risk account missing.');
        $wrong = new LoginRequest($email, 'wrong');
        $valid = new LoginRequest($email, $password);
        $request = Request::fake(method: 'POST', uri: '/login');
        $render = static fn(LoginResult $result): Response => Response::json(['ok' => $result->authenticated()], $result->authenticated() ? 200 : 401);
        $ja4 = 't13d1516h2_0123456789ab_0123456789ab';
        $context = RiskContext::fromTransport('lab-' . $id, 'login', '192.0.2.1', [], ['198.51.100.1'], [$ja4], ['64500']);
        ensure(!$context->attested && $context->ip === '192.0.2.1' && $context->ja4 === null && $context->asn === null, 'Untrusted forwarded risk claims accepted.');
        $attested = RiskContext::fromTransport('lab-' . $id, 'login', '192.0.2.1', [IpCidr::from('192.0.2.1/32')], ['198.51.100.1'], [$ja4], ['64500']);
        ensure($attested->attested && $attested->ip === '198.51.100.1' && $attested->ja4 === $ja4, 'Trusted edge claims lost.');
        $now = time();
        $policy = new RiskPolicy(failureAt: 1, failureWeight: 30);
        $guard = new RiskLogin($authenticator, $policy, $telemetry, $proofs);
        ensure($guard->handle($request, $wrong, $context, $now, $render)->getStatusCode() === 401, 'Initial authentication failure not recorded.');
        ensure($guard->handle($request, $valid, $context, $now, $render)->getStatusCode() === 429, 'Risk challenge not applied.');
        // The provider-verification boundary is seeded with synthetic, already-verified proof.
        // This is not a third-party challenge-provider wire test.
        $token = $proofs->issueClearance($context, $email, bin2hex(random_bytes(32)), $now);
        ensure(is_string($token), 'Clearance issuance failed.');
        ensure($guard->handle($request, $valid, $context, $now, $render, clearance: $token, hardDeny: true)->getStatusCode() === 403, 'Hard deny bypassed.');
        $shadow = new RiskLogin($authenticator, new RiskPolicy(failureAt: 1, failureWeight: 30, shadow: true), $telemetry, $proofs);
        ensure($shadow->handle($request, $valid, $context, $now, $render, clearance: $token)->getStatusCode() === 200, 'Shadow mode blocked valid login.');
        ensure($guard->handle($request, $valid, $context, $now, $render, clearance: $token)->getStatusCode() === 200, 'Valid clearance rejected.');
        ensure($guard->handle($request, $valid, $context, $now, $render, clearance: $token)->getStatusCode() === 429, 'Clearance replay accepted.');
        ensure($telemetry->observe($context, $now, $email)['failedSignatureAttempts'] === 1, 'Denied/challenged requests incorrectly recorded as password failures.');
        $bulkhead = new RiskHashBulkhead($authority, 'lab-' . $id, 1, nonEvictingIsolatedPool: true);
        ensure($bulkhead->provisionDrainedPool(), 'Capacity provisioning failed.');
        $lease = $bulkhead->acquire();
        ensure($lease !== null, 'Capacity lease unavailable.');
        try {
            $limited = new RiskLogin($authenticator, new RiskPolicy(), $telemetry, bulkhead: $bulkhead);
            $response = $limited->handle($request, $valid, $context, $now, $render);
            ensure($response->getStatusCode() === 503 && $response->getHeaderLine('Cache-Control') === 'no-store', 'Exhausted hash capacity admitted work.');
        } finally {
            ensure($bulkhead->release($lease), 'Capacity release failed.');
        }
        ensure($limited->handle($request, $valid, $context, $now, $render)->getStatusCode() === 200, 'Capacity did not recover.');
        $this->app->make(DBLayerFactory::class)->connection()->statement('INSERT INTO experiment_risk (id) VALUES (?)', [$id]);
    }

    /** @throws \Throwable */
    public function verify(int $expected): void
    {
        new Accounts($this->app)->verify($expected);
        ensure(countValue($this->app->make(DBLayerFactory::class)->connection()->scalar('SELECT COUNT(*) FROM experiment_risk')) === $expected, 'Risk workflow count mismatch.');
    }
}
