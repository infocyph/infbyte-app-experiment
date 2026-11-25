<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\Webrick\Middleware\CompressionMiddleware;
use Infocyph\Webrick\Middleware\NegotiationMiddleware;
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\ReleaseCompiler;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Infocyph\Webrick\Router\Url\UrlGenerator;
use Infocyph\Webrick\Router\Url\UrlGeneratorRegistry;
use Psr\Log\NullLogger;

final class LabSignedRouteController
{
    public function report(Request $request, int $id): Response
    {
        $marker = evidenceDirectory() . '/signed-dispatch.log';
        verify(file_put_contents($marker, $id . PHP_EOL, FILE_APPEND | LOCK_EX) !== false, 'Dispatch evidence unavailable.');

        return new Response(200, str_repeat('report:' . $id . ';', 512), [
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function public(): Response
    {
        return new Response(200, 'public');
    }
}

function signedCompiledContracts(string $contract): void
{
    $root = evidenceDirectory() . '/signed-release';
    verify(mkdir($root, 0700), 'Release root unavailable.');
    // Disposable synthetic keys only, deliberately unrelated to application secrets.
    $key = str_repeat('n', 32);
    $old = str_repeat('o', 32);
    $mode = $contract === 'relative' ? SignedUrlConfig::MODE_RELATIVE : SignedUrlConfig::MODE_ABSOLUTE;
    $profile = [
        'generationKey' => $key, 'verificationKeys' => [$old], 'defaultTtl' => 90,
        'signatureParam' => 'proof', 'expiryParam' => 'until', 'algorithm' => 'sha256',
        'payloadMode' => $mode, 'ignoredQueryParams' => ['tracking'],
        'leeway' => $contract === 'leeway' ? 30 : 0,
    ];
    if ($contract === 'retired-key') {
        $profile['verificationKeys'] = [];
    }
    $config = SignedUrlConfig::fromArray($profile);
    $register = static function (Registrar $routes): void {
        $routes->group('/v1', 'api.example.test', [], 'v1.', static function (Registrar $group): void {
            $group->get('/receipt/{id:int}', [LabSignedRouteController::class, 'report'], [
                'as' => 'receipt', 'aliases' => ['legacy'],
                'middleware' => [VerifySignedUrlMiddleware::class, NegotiationMiddleware::class, CompressionMiddleware::class],
                'attributes' => ['produces' => new Produces(['text/plain'], ['utf-8'])],
            ]);
        });
        $routes->get('/public', [LabSignedRouteController::class, 'public'], 'public');
    };
    $builder = ContainerBuilder::create('lab-signed-compiled');
    $builder->factory(VerifySignedUrlMiddleware::class, FactoryDefinition::construct(VerifySignedUrlMiddleware::class, [$profile]));
    $builder->factory(CompressionMiddleware::class, FactoryDefinition::construct(CompressionMiddleware::class, []));
    $builder->factory(NegotiationMiddleware::class, FactoryDefinition::construct(NegotiationMiddleware::class, []));
    $release = new ReleaseCompiler()->compile(
        builder: $builder, register: $register, environment: 'production',
        configFingerprint: hash('xxh128', 'signed-' . $contract),
        intermixPath: $root . '/intermix.php', routerPath: $root . '/webrick.php',
        releaseManifestPath: $root . '/release.json',
    );
    $kernel = CompiledRouterKernel::fromPrevalidatedArtifact(
        log: new NullLogger(),
        matcher: match (getenv('LAB_MATCHER')) {
            'fused' => FusedMatcher::make(), 'sharded' => ShardedMatcher::make(),
            default => throw new RuntimeException('Unknown matcher.'),
        },
        container: $builder->productionPrevalidated($release['intermix']['path'], $release['intermix']['digest']),
        artifactPath: $release['webrick']['path'], trustedArtifactFingerprint: $release['webrick']['fingerprint'],
        environment: 'production', configFingerprint: $release['config_fingerprint'],
        urlBaseUri: 'https://origin.example.test', signedUrlConfig: $config,
    );
    $urls = UrlGeneratorRegistry::get();
    $calls = static function (): int {
        $path = evidenceDirectory() . '/signed-dispatch.log';

        return is_file($path) ? count(file($path, FILE_IGNORE_NEW_LINES)) : 0;
    };
    $dispatch = static function (string $url, array $headers = [], string $method = 'GET') use ($kernel): Response {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $kernel->handle(Request::fake(method: $method, uri: $url, headers: $headers)->withQueryParams($query));
    };
    $params = ['id' => 42];
    $query = ['tenant' => 'alpha', 'format' => 'text'];
    $path = 'https://api.example.test/v1/receipt/42';
    $signed = $urls->signed('v1.receipt', $params, $query);
    $assertOk = static function (string $url) use ($dispatch): void {
        $result = $dispatch($url, ['Accept' => 'text/plain']);
        verify($result->getStatusCode() === 200 && $result->getStringBody() === str_repeat('report:42;', 512), 'Signed response did not match handler.');
    };
    $reject = static function (string $url, int $status) use ($dispatch, $calls): void {
        $before = $calls();
        verify($dispatch($url)->getStatusCode() === $status && $calls() === $before, 'Rejected URL reached handler or returned wrong status.');
    };
    switch ($contract) {
        case 'permanent':
            verify(str_starts_with($signed, $path . '?') && !str_contains($signed, 'until='), 'Permanent URL acquired expiry or wrong domain.');
            $assertOk($signed);
            $assertOk($urls->signed('v1.legacy', $params, $query));
            verify($calls() === 2, 'Alias did not resolve same business handler.');
            break;
        case 'temporary':
            $before = time();
            $temporary = $urls->temporary('v1.receipt', $params, $query);
            parse_str((string) parse_url($temporary, PHP_URL_QUERY), $actual);
            verify((int) $actual['until'] >= $before + 90 && (int) $actual['until'] <= time() + 90, 'Configured default TTL differs.');
            $assertOk($temporary);
            $assertOk($urls->temporaryUntil('v1.receipt', new DateTimeImmutable('+2 minutes'), $params, $query));
            $reject($urls->temporaryUntil('v1.receipt', time() - 10, $params, $query), 410);
            break;
        case 'leeway':
            $assertOk($urls->temporaryUntil('v1.receipt', time() - 10, $params, $query));
            $reject($urls->temporaryUntil('v1.receipt', time() - 40, $params, $query), 410);
            break;
        case 'canonical':
            $second = $urls->signed('v1.receipt', $params, array_reverse($query, true));
            verify($signed === $second, 'Top-level query ordering changed signature.');
            parse_str((string) parse_url($signed, PHP_URL_QUERY), $actual);
            $expected = $query;
            ksort($expected);
            verify(hash_equals(hash_hmac('sha256', $path . '?' . http_build_query($expected, '', '&', PHP_QUERY_RFC3986), $key), $actual['proof']), 'Independent HMAC payload differs.');
            $assertOk($path . '?' . http_build_query(array_reverse($actual, true) + ['tracking' => 'arbitrary'], '', '&', PHP_QUERY_RFC3986));
            break;
        case 'tamper':
            $reject(str_replace('tenant=alpha', 'tenant=foreign', $signed), 403);
            $reject(str_replace('/42?', '/43?', $signed), 403);
            $reject(str_replace('https://', 'http://', $signed), 403);
            $reject(str_replace('api.example.test', 'foreign.example.test', $signed), 404);
            $reject($path . '?tenant=alpha', 400);
            $assertOk($signed);
            verify($calls() === 1, 'Tampering poisoned later valid request.');
            break;
        case 'invalid-expiry':
            foreach (['-1', 'not-a-number', '999999999999999999999999', ''] as $invalid) {
                $reject($signed . '&until=' . rawurlencode($invalid), 400);
            }
            $reject($signed . '&until%5B%5D=1', 400);
            $assertOk($signed);
            break;
        case 'relative':
            $relative = $urls->signed('v1.receipt', $params, $query, absolute: false);
            verify(str_starts_with($relative, '/v1/receipt/42?'), 'Relative URL is not relative.');
            $assertOk('https://api.example.test' . $relative);
            $assertOk('http://api.example.test' . $relative);
            $reject('https://foreign.example.test' . $relative, 404);
            break;
        case 'rotation':
        case 'retired-key':
            $oldProfile = array_replace($profile, ['generationKey' => $old, 'verificationKeys' => []]);
            $oldGenerator = new UrlGenerator('https://origin.example.test', ['v1.receipt' => ['/v1/receipt/{id:int}', 'api.example.test']], signedConfig: SignedUrlConfig::fromArray($oldProfile));
            $oldUrl = $oldGenerator->signed('v1.receipt', $params, $query);
            if ($contract === 'rotation') {
                $assertOk($oldUrl);
            } else {
                $reject($oldUrl, 403);
            }
            $assertOk($signed);
            break;
        case 'composition':
            $before = $calls();
            verify($dispatch($path, ['Accept' => 'image/png', 'Accept-Encoding' => 'gzip'])->getStatusCode() === 400 && $calls() === $before, 'Negotiation bypassed earlier signature requirement.');
            verify($dispatch($signed, ['Accept' => 'image/png'])->getStatusCode() === 406 && $calls() === $before, 'Rejected media reached handler.');
            $encoded = $dispatch($signed, ['Accept' => 'text/plain', 'Accept-Encoding' => 'gzip']);
            verify($encoded->getStatusCode() === 200 && $encoded->getHeaderLine('Content-Encoding') === 'gzip'
                && gzdecode($encoded->getStringBody()) === str_repeat('report:42;', 512), 'Signed response coding differs.');
            verify($encoded->getHeaderLine('Cache-Control') === 'private, no-store' && $calls() === $before + 1, 'Response privacy or handler count changed.');
            break;
        case 'registry':
            verify(UrlGeneratorRegistry::frozen() && UrlGeneratorRegistry::get() === $urls, 'Compiled URL generator registry is not frozen.');
            expectFailure(UrlGeneratorRegistry::reset(...), LogicException::class, 'Frozen registry could be reset.');
            expectFailure(static fn() => UrlGeneratorRegistry::bind($urls), LogicException::class, 'Frozen registry could be rebound.');
            foreach (['proof', 'until'] as $reserved) {
                expectFailure(static fn() => $urls->signed('v1.receipt', $params, [$reserved => 'user-input']), InvalidArgumentException::class, 'Reserved query parameter accepted.');
            }
            expectFailure(static fn() => $urls->urlFor('v1.receipt'), InvalidArgumentException::class, 'Missing route parameter accepted.');
            expectFailure(static fn() => $urls->urlFor('v1.missing'), InvalidArgumentException::class, 'Unknown route accepted.');
            verify($urls->urlFor('v1.legacy', $params, absolute: true) === $path, 'Alias lookup failed after rejected operations.');
            $assertOk($signed);
            break;
        default:
            throw new InvalidArgumentException('Unknown signed route contract.');
    }
    $before = $calls();
    verify($dispatch('https://origin.example.test/public')->getStringBody() === 'public' && $calls() === $before, 'Signed middleware escaped route scope.');
    echo "Compiled signed route $contract " . getenv('LAB_MATCHER') . " passed\n";
}
