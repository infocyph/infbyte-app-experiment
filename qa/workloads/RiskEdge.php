<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Authentication\Login\Authenticator;
use Infocyph\Foundation\Auth\Authentication\Login\LoginRequest;
use Infocyph\Foundation\Auth\Authentication\Login\LoginResult;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Security\Risk\RiskAuthority;
use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Foundation\Security\Risk\RiskHashBulkhead;
use Infocyph\Foundation\Security\Risk\RiskLogin;
use Infocyph\Foundation\Security\Risk\RiskPolicy;
use Infocyph\Foundation\Security\Risk\RiskTelemetry;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\IpCidr;
use Infocyph\Webrick\Response\Response;

final readonly class RiskEdge
{
    public function __construct(private Application $app) {}

    public static function seed(Application $app): void
    {
        new Accounts($app)->setup();
        $auth = $app->make(AuthServices::class);
        ensure($auth->accounts()->create('edge@example.test', $auth->passwordHasher()->hash((string) getenv('LAB_EDGE_PASSWORD')))->account !== null, 'Edge account missing.');
        ensure(new self($app)->bulkhead()->provisionDrainedPool(), 'Drained hash capacity provisioning failed.');
    }

    private function bulkhead(): RiskHashBulkhead
    {
        $cache = $this->app->make(CacheManager::class)->store('shared');
        return new RiskHashBulkhead(new RiskAuthority($cache, (string) getenv('LAB_EDGE_AUTHORITY'), shared: true),
            'lab-edge', 1, nonEvictingIsolatedPool: true);
    }

    public function ordinary(): Response
    {
        ensure(getenv('LAB_POOL') === 'ordinary', 'Ordinary request reached protected pool.');
        return Response::json(['ok' => true, 'pool' => 'ordinary']);
    }

    public function login(Request $request): Response
    {
        ensure(getenv('LAB_POOL') === 'protected', 'Login reached ordinary worker pool.');
        $peer = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        if ($peer !== '127.0.0.2') {
            return Response::json(['error' => 'untrusted_edge'], 403, ['Cache-Control' => 'no-store']);
        }
        $context = RiskContext::fromTransport('lab-edge', 'login', $peer, [IpCidr::from('127.0.0.2/32')],
            $request->getHeader('X-Lab-Client-IP'), $request->getHeader('X-Lab-JA4'), $request->getHeader('X-Lab-ASN'));
        ensure($context->attested && $context->ip === '127.0.0.1' && $context->ja4 === null && $context->asn === null, 'Edge did not overwrite or remove untrusted signals.');
        $password = $request->post('password');
        if (!is_string($password) || strlen($password) > 256) {
            return Response::json(['error' => 'invalid'], 400, ['Cache-Control' => 'no-store']);
        }
        $telemetry = new RiskTelemetry($this->app->make(CacheManager::class)->store('shared'),
            (string) getenv('LAB_EDGE_TELEMETRY'), shared: true, windowSeconds: 3600);
        $guard = new RiskLogin($this->app->make(Authenticator::class), new RiskPolicy(failureAt: 1, failureWeight: 30),
            $telemetry, bulkhead: $this->bulkhead());
        return $guard->handle($request, new LoginRequest('edge@example.test', $password), $context,
            (int) getenv('LAB_EDGE_TIME'), static fn(LoginResult $result): Response =>
                Response::json(['ok' => $result->authenticated(), 'pool' => 'protected'], $result->authenticated() ? 200 : 401));
    }
}
