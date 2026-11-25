<?php

declare(strict_types=1);

use Experiment\LabAttributeController;
use Experiment\LabRouteMarker;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Middleware\CompressionMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\ReleaseCompiler;
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Psr\Log\NullLogger;

function routeAttributeWorkload(): void
{
    $dir = evidenceDirectory() . '/attribute-release';
    verify(mkdir($dir, 0700), 'Cannot create release directory.');
    $register = static function (Registrar $routes): void {
        AttributeRouteLoader::registerFromDirs(
            $routes,
            ['Experiment\\' => getenv('PROJECT') . '/lab'],
            static fn(SplFileInfo $file): bool => $file->getFilename() === 'LabAttributeController.php',
        );
        $routes->resource('records', '/records', LabAttributeController::class, [
            'param' => 'record',
            'only' => ['index', 'store', 'show', 'update', 'patch', 'destroy'],
            'patch_action' => 'patch',
            'names' => ['show' => 'records.inspect'],
            'middleware' => [LabRouteMarker::class],
        ]);
    };
    $explicit = new Collection();
    AttributeRouteLoader::register(new Registrar($explicit), [LabAttributeController::class, LabAttributeController::class]);
    verify(count($explicit->all()) === 2, 'Explicit registration did not deduplicate controller.');
    $collection = new Collection();
    $register(new Registrar($collection));
    $route = $collection->findByName('lab.attribute.show');
    verify($route !== null && $route->getPath() === '/attributes/items/{id:int}', 'Named attribute metadata missing.');
    verify($route->getProduces()?->types === ['application/json'] && $route->getCorsPolicy()?->origins === ['https://client.example.test'], 'Attribute negotiation/CORS metadata missing.');
    verify($collection->findByName('records.inspect')?->getPath() === '/records/{record}' && $collection->findByName('records.create') === null, 'Resource names/options not preserved.');

    $builder = ContainerBuilder::create('lab-attribute-resource');
    $release = new ReleaseCompiler()->compile(
        builder: $builder,
        register: $register,
        environment: 'production',
        configFingerprint: hash('xxh128', 'attribute-resource'),
        intermixPath: $dir . '/intermix.php',
        routerPath: $dir . '/webrick.php',
        releaseManifestPath: $dir . '/release.json',
    );
    $kernel = CompiledRouterKernel::fromPrevalidatedArtifact(
        log: new NullLogger(),
        matcher: match (getenv('LAB_MATCHER')) {
            'fused' => FusedMatcher::make(),
            'sharded' => ShardedMatcher::make(),
            default => throw new RuntimeException('Unexpected matcher.'),
        },
        container: $builder->productionPrevalidated($release['intermix']['path'], $release['intermix']['digest']),
        artifactPath: $release['webrick']['path'],
        trustedArtifactFingerprint: $release['webrick']['fingerprint'],
        environment: 'production',
        configFingerprint: $release['config_fingerprint'],
    );
    $request = static fn(string $method, string $uri): Response => $kernel->handle(Request::fake(method: $method, uri: $uri));
    $attribute = $request('GET', 'https://api.example.test/attributes/items/42');
    verify($attribute->getStatusCode() === 200 && json_decode((string) $attribute->getStringBody(), true, flags: JSON_THROW_ON_ERROR) === ['id' => 42], 'Attribute dispatch mismatch.');
    verify($request('GET', 'https://api.example.test/attributes/legacy/42')->getStatusCode() === 200, 'Repeatable route attribute missing.');
    verify($request('GET', 'https://foreign.example.test/attributes/items/42')->getStatusCode() === 404, 'Attribute domain escaped.');
    verify($request('GET', 'https://api.example.test/attributes/items/abc')->getStatusCode() === 404, 'Attribute constraint escaped.');
    foreach ([
        ['GET', '/records', 200, 'index'],
        ['POST', '/records', 201, 'store'],
        ['GET', '/records/42', 200, 'show'],
        ['PUT', '/records/42', 200, 'update'],
        ['PATCH', '/records/42', 200, 'patch'],
        ['DELETE', '/records/42', 200, 'destroy'],
    ] as [$verb, $path, $status, $action]) {
        $response = $request($verb, 'https://api.example.test' . $path);
        $payload = json_decode((string) $response->getStringBody(), true, flags: JSON_THROW_ON_ERROR);
        verify($response->getStatusCode() === $status && ($payload['action'] ?? null) === $action
            && (!str_ends_with($path, '/42') || ($payload['id'] ?? null) === '42')
            && $response->getHeaderLine('X-Lab-Marker') === 'applied', 'Resource dispatch or middleware mismatch.');
    }
    verify($request('POST', 'https://api.example.test/records/42')->getStatusCode() === 405, 'Resource method boundary failed.');
    verify($attribute->getHeaderLine('X-Lab-Marker') === 'applied', 'Route attribute middleware was discarded.');
    echo "Attribute discovery, metadata, repeated routes, domains, resource options and compiled dispatch passed\n";
}

function compressionCodingsWorkload(): void
{
    $body = str_repeat("laboratory report: 123456789\n", 1024);
    $handler = static fn(Request $request): Response => new Response(200, $body, ['Content-Type' => 'text/plain', 'ETag' => '"release"']);
    $decode = ['zstd' => 'zstd_uncompress', 'br' => 'brotli_uncompress', 'gzip' => 'gzdecode', 'deflate' => 'gzuncompress'];
    $compression = new CompressionMiddleware(minBytes: 1, prefOrder: array_keys($decode));
    $request = static fn(string $coding): Request => Request::fake(headers: ['Accept-Encoding' => $coding]);
    foreach ($decode as $coding => $decoder) {
        verify(is_callable($decoder), 'Required coding decoder unavailable: ' . $coding);
        $response = $compression($request($coding), $handler);
        verify($response->getHeaderLine('Content-Encoding') === $coding && $decoder((string) $response->getBody()) === $body, 'Coding roundtrip failed: ' . $coding);
        verify((int) $response->getHeaderLine('Content-Length') === strlen((string) $response->getBody()), 'Encoded length mismatch.');
        verify(str_contains(strtolower($response->getHeaderLine('Vary')), 'accept-encoding'), 'Coding variation missing.');
    }
    verify($compression($request('gzip;q=0.8, identity;q=1'), $handler)->getStringBody() === $body, 'Identity preference ignored.');
    verify($compression($request('br;q=1, zstd;q=0.5, identity;q=0'), $handler)->getHeaderLine('Content-Encoding') === 'br', 'Quality preference ignored.');
    verify($compression($request('gzip;q=1, zstd;q=1, identity;q=0'), $handler)->getHeaderLine('Content-Encoding') === 'zstd', 'Configured tie-break ignored.');
    verifyHttpStatus(static fn() => $compression($request('identity;q=0, *;q=0'), $handler), 406);
    foreach ([
        new Response(206, $body, ['Content-Type' => 'text/plain', 'Content-Range' => 'bytes 0-99/1000']),
        new Response(200, $body, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-transform']),
        new Response(200, $body, ['Content-Type' => 'application/octet-stream']),
    ] as $excluded) {
        verify($compression($request('gzip'), static fn(Request $r): Response => $excluded)->getHeaderLine('Content-Encoding') === '', 'Excluded representation was compressed.');
    }
    echo "All four content codings, independent decode, negotiation, rejection and bypass boundaries passed\n";
}
