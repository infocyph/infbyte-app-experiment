<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\Foundation\Http\Resource\JsonResource;
use Infocyph\Foundation\Http\Resource\ResourceCollection;
use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Foundation\Security\Risk\RiskTelemetry;
use Infocyph\Webrick\Request\Support\IpCidr;

function telemetryContext(string $tenant = 'alpha', string $purpose = 'login', string $ip = '198.51.100.3', string $signature = 't13d1516h2_0123456789ab_0123456789ab'): RiskContext
{
    return RiskContext::fromTransport($tenant, $purpose, '192.0.2.1', [IpCidr::from('192.0.2.1/32')], [$ip], [$signature], ['64500']);
}

function foundationRiskIsolation(string $backend): void
{
    [$cache, $peer] = cacheContractPeers($backend, 'risk-isolation');
    $key = str_repeat('r', 32);
    $a = new RiskTelemetry($cache, $key, shared: true, concentrationAt: 3);
    $b = new RiskTelemetry($peer, $key, shared: true, concentrationAt: 3);
    $context = telemetryContext();
    $now = intdiv(time(), 60) * 60;
    $empty = ['familiar' => false, 'failedSignatureAttempts' => 0, 'concentrated' => false, 'reliable' => true];
    verify($a->observe($context, $now, 'account') === $empty, 'Qualified cold observation differs.');
    for ($i = 1; $i <= 3; ++$i) {
        verify($a->recordFailure($context, $now, 'account'), 'Failure accounting did not settle.');
        verify($b->observe($context, $now, 'account') === ['familiar' => false, 'failedSignatureAttempts' => $i, 'concentrated' => $i >= 3, 'reliable' => true], 'Threshold or independent observation differs.');
    }
    verify($a->recordSuccess($context) && $b->observe($context, $now)['familiar'] === true, 'Successful fingerprint familiarity not shared.');
    verify($b->observe(telemetryContext('beta'), $now, 'account') === $empty && $b->observe(telemetryContext(purpose: 'password-reset'), $now, 'account') === $empty, 'Tenant/purpose scope leaked.');
    verify($b->observe($context, $now + 60, 'account') === ['familiar' => true, 'failedSignatureAttempts' => 0, 'concentrated' => false, 'reliable' => true], 'Fixed-window reset lost familiarity or retained count.');
    $different = telemetryContext(ip: '203.0.113.3', signature: 't13d1516h2_abcdef012345_0123456789ab');
    verify($b->observe($different, $now, 'account')['failedSignatureAttempts'] === 3 && $b->observe($different, $now, 'other')['failedSignatureAttempts'] === 0, 'Account dimension or signature separation differs.');
    $local = new RiskTelemetry($peer, $key, shared: false);
    verify(!$local->observe($context, $now)['reliable'] && $local->observe(telemetryContext('beta'), $now)['familiar'] === null, 'Unqualified absence claimed as reliable.');
    $direct = RiskContext::fromTransport('alpha', 'login', '198.51.100.3', [], ['203.0.113.3'], [$context->ja4], ['64500']);
    verify(!$direct->attested && $direct->ja4 === null && $direct->asn === null && $b->observe($direct, $now)['familiar'] === null, 'Untrusted signature influenced telemetry.');
    echo "Foundation risk telemetry $backend isolation, windows and trust boundaries passed\n";
}

function foundationRiskConcurrent(string $backend): void
{
    $root = evidenceDirectory() . '/risk-concurrent';
    verify(mkdir($root, 0700), 'Risk fixture directory unavailable.');
    $key = str_repeat('s', 32);
    $now = intdiv(time(), 60) * 60;
    $namespace = 'risk-concurrent';
    $results = parallelRace(static function () use ($backend, $root, $key, $now, $namespace): int {
        $cache = sharedAtomicCache($backend, $namespace, $root);
        $telemetry = new RiskTelemetry($cache, $key, shared: true, concentrationAt: 12);
        return (int) $telemetry->recordFailure(telemetryContext(), $now, 'account');
    }, 'risk-concurrent', 12);
    verify(count($results) === 12 && array_sum($results) === 12, 'Concurrent telemetry returned incomplete accounting.');
    $cache = sharedAtomicCache($backend, $namespace, $root);
    $peer = new RiskTelemetry($cache, $key, shared: true, concentrationAt: 12);
    verify($peer->observe(telemetryContext(), $now, 'account') === ['familiar' => false, 'failedSignatureAttempts' => 12, 'concentrated' => true, 'reliable' => true], 'Independent observer lost or duplicated failures.');
    verify($peer->observe(telemetryContext('beta'), $now, 'account')['failedSignatureAttempts'] === 0, 'Concurrent failures crossed tenant.');
    verify($peer->recordSuccess(telemetryContext()) && $peer->observe(telemetryContext(), $now, 'account')['failedSignatureAttempts'] === 12, 'Familiarity changed failure accounting.');
    echo "Foundation risk telemetry $backend twelve-process failure accounting passed\n";
}

function foundationResourceContracts(): void
{
    $seen = 0;
    $source = (static function (): Generator {
        yield 100 => ['id' => 1, 'private' => 'never-export'];
        yield 200 => ['id' => 2, 'private' => 'never-export'];
    })();
    $resource = new ResourceCollection($source, static function (array $row) use (&$seen): JsonResource {
        ++$seen;
        return new class($row) extends JsonResource {
            public function resolve(): array
            {
                return ['id' => $this->resource['id']];
            }
        };
    });
    verify($seen === 0, 'Resource eagerly consumed input.');
    verify(json_encode($resource, JSON_THROW_ON_ERROR) === '[{"id":1},{"id":2}]' && $seen === 2, 'Resource projection, cardinality or private field exclusion failed.');
    verify(json_encode(new ResourceCollection([], static fn($value) => $value), JSON_THROW_ON_ERROR) === '[]', 'Empty resource collection differs.');
    $failure = new DomainException('transform failure');
    expectFailure(static fn() => json_encode(new ResourceCollection([1], static fn() => throw $failure), JSON_THROW_ON_ERROR), DomainException::class, 'Resource suppressed transformer failure.');
    echo "Foundation resource lazy input, explicit projection and failure propagation passed\n";
}
