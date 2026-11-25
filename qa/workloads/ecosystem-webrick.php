<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Interop\CacheLayer\AtomicCounterAdapter;
use Infocyph\Webrick\Middleware\CookieEncryptionMiddleware;
use Infocyph\Webrick\Middleware\CorsMiddleware;
use Infocyph\Webrick\Middleware\GatewayHardeningMiddleware;
use Infocyph\Webrick\Middleware\RequestLimitsMiddleware;
use Infocyph\Webrick\Middleware\ResponseCacheMiddleware;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Middleware\MaintenanceModeMiddleware;
use Infocyph\Webrick\Middleware\InputSanitizerMiddleware;
use Infocyph\Webrick\Middleware\ResponseLinterMiddleware;
use Infocyph\Webrick\Middleware\SecurityPolicyMiddleware;
use Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware;
use Infocyph\Webrick\Support\InputSanitizer;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Response\Response;

function verifyHttpStatus(callable $operation, int $status): void
{
    try {
        $operation();
    } catch (HttpException $failure) {
        verify($failure->getStatusCode() === $status, 'Unexpected HTTP rejection status.');

        return;
    }
    throw new RuntimeException('Expected HTTP rejection was not raised.');
}

function gatewayWorkload(): void
{
    $calls = 0;
    $next = static function (Request $request) use (&$calls): Response {
        ++$calls;

        return new Response(200, 'accepted');
    };
    $gateway = new GatewayHardeningMiddleware(trustedHosts: ['lab.example'], enforceHttps: true);
    $redirect = $gateway(Request::fake(headers: ['Host' => 'lab.example'], uri: 'http://lab.example/orders'), $next);
    verify($redirect->getStatusCode() === 308 && $redirect->getHeaderLine('Location') === 'https://lab.example/orders', 'HTTPS redirect mismatch.');
    verifyHttpStatus(static fn() => $gateway(Request::fake(headers: ['Host' => 'foreign.example'], uri: 'https://foreign.example/orders'), $next), 400);
    verify($gateway(Request::fake(headers: ['Host' => 'lab.example'], uri: 'https://lab.example/orders'), $next)->getStatusCode() === 200 && $calls === 1, 'Gateway dispatch boundary failed.');

    $limits = new RequestLimitsMiddleware(maxHeaderBytes: 256, maxHeaderCount: 4, maxBodyBytes: 64);
    verifyHttpStatus(static fn() => $limits(Request::fake(headers: ['X-Large' => str_repeat('x', 300)]), $next), 431);
    verifyHttpStatus(static fn() => $limits(Request::fake(headers: ['X-Fields' => ['1', '2', '3', '4', '5']]), $next), 431);
    verifyHttpStatus(static fn() => $limits(Request::fake(headers: ['Content-Length' => '65'], method: 'POST'), $next), 413);
    verifyHttpStatus(static fn() => $limits(Request::fake(headers: ['Content-Length' => 'invalid'], method: 'POST'), $next), 400);
    verifyHttpStatus(static fn() => $limits(Request::fake(headers: ['Content-Length' => '12', 'Transfer-Encoding' => 'chunked'], method: 'POST'), $next), 400);
    verify($limits(Request::fake(headers: ['Content-Length' => '0'], method: 'POST'), $next)->getStatusCode() === 200 && $calls === 2, 'Request limit pass-through failed.');

    $cors = new CorsMiddleware(origins: ['https://client.example'], methods: 'GET, POST', allowHeaders: ['Content-Type'], maxAgeSeconds: 60, allowCredentials: true);
    $preflight = static fn(string $origin, string $method): Request => Request::fake(headers: ['Origin' => $origin, 'Access-Control-Request-Method' => $method, 'Access-Control-Request-Headers' => 'Content-Type'], method: 'OPTIONS');
    $allowed = $cors($preflight('https://client.example', 'POST'), $next);
    verify($allowed->getStatusCode() === 204 && $allowed->getHeaderLine('Access-Control-Allow-Origin') === 'https://client.example' && $allowed->getHeaderLine('Access-Control-Allow-Credentials') === 'true', 'CORS preflight mismatch.');
    verifyHttpStatus(static fn() => $cors($preflight('https://foreign.example', 'POST'), $next), 403);
    verifyHttpStatus(static fn() => $cors($preflight('https://client.example', 'DELETE'), $next), 403);
    verify($cors(Request::fake(headers: ['Origin' => 'https://foreign.example']), $next)->getHeaderLine('Access-Control-Allow-Origin') === '', 'Foreign origin received access grant.');
    verify($calls === 3, 'Rejected/preflight requests reached the handler.');
    echo "Gateway HTTPS and host enforcement, request limits and CORS policy passed\n";
}

function responseCacheWorkload(): void
{
    $namespace = 'lab-response-cache';
    $one = new ResponseCacheMiddleware(store: Cache::redis($namespace), ttlSeconds: 60);
    $two = new ResponseCacheMiddleware(store: Cache::redis($namespace), ttlSeconds: 60);
    $calls = 0;
    $next = static function (Request $request) use (&$calls): Response {
        return new Response(200, (string) ++$calls, ['Cache-Control' => 'public, max-age=60', 'Vary' => 'Accept-Language']);
    };
    $request = static fn(string $uri, string $language = 'en', array $extra = []): Request => Request::fake(headers: ['Accept-Language' => $language] + $extra, uri: $uri);
    $uri = 'https://lab.example/orders?page=1';
    $body = static fn(Response $response): string => (string) $response->getBody();
    verify($body($one($request($uri), $next)) === '1', 'Initial response failed.');
    verify($body($two($request($uri), $next)) === '1' && $calls === 1, 'Shared response cache missed.');
    verify($body($two($request($uri, 'bn'), $next)) === '2', 'Language variant crossed cache key.');
    verify($body($two($request('https://lab.example/orders?page=2'), $next)) === '3', 'Query variant crossed cache key.');
    verify($body($two($request('https://other.example/orders?page=1'), $next)) === '4', 'Host variant crossed cache key.');
    for ($i = 0; $i < 2; ++$i) {
        $two($request($uri, extra: ['Authorization' => 'Bearer disposable']), $next);
        $two($request($uri, extra: ['Cookie' => 'session=disposable']), $next);
    }
    verify($calls === 8, 'Personalized request was served from or stored in shared cache.');
    $private = static function (Request $request) use (&$calls): Response {
        return new Response(200, (string) ++$calls, ['Cache-Control' => 'private, no-store']);
    };
    $one($request('https://lab.example/private'), $private);
    $two($request('https://lab.example/private'), $private);
    verify($calls === 10 && $body($one($request($uri), $next)) === '1', 'Private response was cached or public cache was poisoned.');
    echo "Real Redis response reuse, vary/query/host separation and personalized bypass passed\n";
}

function throttleWorkload(): void
{
    $scope = 'lab-throttle-' . bin2hex(random_bytes(8));
    $now = time();
    $request = static fn(string $identity, int $cost = 1): Request => (new Request('GET', Uri::from('https://lab.example/orders'), ['REQUEST_TIME' => $now], ['Host' => 'lab.example']))->withAttribute('identity', $identity)->withAttribute('rate_cost.thm', $cost);
    $make = static fn(): ThrottleMiddleware => new ThrottleMiddleware(max: 5, window: 3600, identifierResolver: static fn(Request $req): mixed => $req->getAttribute('identity'), scope: $scope, counterStore: new AtomicCounterAdapter(AtomicCounters::redis('lab-throttle')));
    $next = static fn(Request $req): Response => new Response(200, 'accepted');
    $results = parallelRace(static function () use ($make, $request, $next): int {
        try {
            verify($make()($request('shared'), $next)->getStatusCode() === 200, 'Throttle success status mismatch.');

            return 1;
        } catch (HttpException $failure) {
            verify($failure->getStatusCode() === 429 && isset($failure->getHeaders()['Retry-After']), 'Unexpected throttle rejection.');

            return 0;
        }
    }, 'throttle-admission');
    verify(array_sum($results) === 5, 'Concurrent throttle did not enforce exact admission budget.');
    $middleware = $make();
    verify($middleware($request('other', 3), $next)->getStatusCode() === 200, 'Independent identity was denied.');
    verifyHttpStatus(static fn() => $middleware($request('other', 3), $next), 429);
    echo "12-worker Redis throttle exact admission, retry metadata, identity and weighted cost passed\n";
}

function cookieProtectionWorkload(): void
{
    $keys = [random_bytes(32), random_bytes(32)];
    $store = Cache::redis('lab-encrypted-cookies');
    $one = new CookieEncryptionMiddleware($keys, maxBytes: 256, store: $store);
    $two = $one->rotateToKid(1);
    $request = Request::fake(uri: 'https://lab.example/');
    $issue = static function (CookieEncryptionMiddleware $middleware, string $value) use ($request): array {
        $response = $middleware($request, static fn(Request $req): Response => new Response(200, '', ['Set-Cookie' => 'enc_cart=' . rawurlencode($value) . '; Path=/']));
        $cookies = [];
        foreach ($response->getHeader('Set-Cookie') as $line) {
            verify(str_contains(strtolower($line), 'secure') && str_contains(strtolower($line), 'httponly'), 'Protected cookie security attributes missing.');
            [$name, $encoded] = explode('=', explode(';', $line, 2)[0], 2);
            $cookies[$name] = rawurldecode($encoded);
        }
        verify(isset($cookies['enc_cart']) && $cookies['enc_cart'] !== $value, 'Plaintext cookie escaped.');

        return $cookies;
    };
    $read = static function (CookieEncryptionMiddleware $middleware, array $cookies) use ($request): string {
        $response = $middleware($request->withCookieParams($cookies), static fn(Request $req): Response => new Response(200, (string) ($req->getCookieParams()['enc_cart'] ?? 'missing')));

        return (string) $response->getBody();
    };
    $small = $issue($one, 'cart-42');
    verify($read($two, $small) === 'cart-42', 'Old key failed after rotation.');
    $fresh = $issue($two, 'cart-43');
    verify($read($one, $fresh) === 'cart-43', 'New key ring cookie not interoperable.');
    $tampered = $small;
    $tampered['enc_cart'][10] = $tampered['enc_cart'][10] === 'A' ? 'B' : 'A';
    verify($read($two, $tampered) === 'missing', 'Tampered encrypted cookie accepted.');
    $large = bin2hex(random_bytes(4096));
    $oversized = $issue($two, $large);
    verify(count($oversized) === 1 && $read($one, $oversized) === $large, 'Redis backed oversized cookie roundtrip failed.');
    echo "Encrypted cookie rotation, tamper rejection, secure attributes and real Redis storage fallback passed\n";
}

function middlewarePolicyWorkload(): void
{
    $root = evidenceDirectory() . '/maintenance';
    $file = $root . '/down';
    verify(mkdir($root, 0700), 'Maintenance root unavailable.');
    $one = new MaintenanceModeMiddleware(file: $file, retryAfter: 17, refreshMilliseconds: 0);
    $two = new MaintenanceModeMiddleware(file: $file, retryAfter: 17, refreshMilliseconds: 0);
    $calls = 0;
    $next = static function (Request $request) use (&$calls): Response {
        ++$calls;
        return Response::plaintext('ready');
    };
    $request = Request::fake(uri: 'https://lab.example.test/orders');
    verify($one($request, $next)->getStatusCode() === 200, 'Healthy maintenance gate blocked.');
    foreach (['deployment in progress', ''] as $message) {
        verify(file_put_contents($file . '.tmp', $message) !== false && rename($file . '.tmp', $file), 'Maintenance sentinel publication failed.');
        clearstatcache(true, $file);
        foreach ([$one, $two] as $gate) {
            try {
                $gate($request, $next);
                throw new LogicException('Maintenance reached handler.');
            } catch (HttpException $failure) {
                verify($failure->getStatusCode() === 503 && (string) $failure->getHeaders()['Retry-After'] === '17', 'Maintenance response metadata mismatch.');
                verify(str_contains($failure->getMessage(), $message === '' ? 'Service is down for maintenance.' : $message), 'Maintenance message mismatch.');
            }
        }
    }
    verify($calls === 1, 'Maintenance executed application.');
    verify(unlink($file), 'Maintenance sentinel removal failed.');
    clearstatcache(true, $file);
    verify($one($request, $next)->getStatusCode() === 200 && $two($request, $next)->getStatusCode() === 200 && $calls === 3, 'Worker-local maintenance recovery failed.');

    $payload = ['name' => "  A\u{200B}  B  ", 'empty' => '  ', 'password' => '  preserve  ', 'nested' => ['raw_token' => '  raw  ', 'value' => "  C\u{00A0}D  "], 'number' => 0, 'flag' => false];
    $normalized = ['name' => 'A B', 'empty' => null, 'password' => '  preserve  ', 'nested' => ['raw_token' => '  raw  ', 'value' => 'C D'], 'number' => 0, 'flag' => false];
    $sanitizer = new InputSanitizer(collapseWs: true, normalizeUnicode: true, skipKeys: ['password'], skipKeyPatterns: ['/^raw_/']);
    $read = static fn(Request $r): Response => Response::json(['query' => $r->getQueryParams(), 'body' => $r->getParsedBody()]);
    foreach (['application/json', 'application/x-www-form-urlencoded', 'text/plain'] as $contentType) {
        $incoming = Request::fake(method: 'POST', headers: ['Content-Type' => $contentType])->withQueryParams($payload)->withParsedBody($payload);
        $default = new InputSanitizerMiddleware($sanitizer);
        verify(json_decode((string) $default($incoming, $read)->getStringBody(), true, flags: JSON_THROW_ON_ERROR) === ['query' => $payload, 'body' => $payload], 'Default middleware changed input.');
        foreach ([false, true] as $query) {
            foreach ([false, true] as $form) {
                foreach ([false, true] as $json) {
                    $middleware = new InputSanitizerMiddleware($sanitizer, touchQuery: $query, touchFormBodies: $form, touchJsonBodies: $json);
                    $result = json_decode((string) $middleware($incoming, $read)->getStringBody(), true, flags: JSON_THROW_ON_ERROR);
                    $touch = ($form && $contentType === 'application/x-www-form-urlencoded') || ($json && $contentType === 'application/json');
                    verify($result === ['query' => $query ? $normalized : $payload, 'body' => $touch ? $normalized : $payload], 'Opt-in normalization boundary mismatch.');
                    verify($incoming->getQueryParams() === $payload && $incoming->getParsedBody() === $payload, 'Original request was mutated.');
                }
            }
        }
    }
    verify((new InputSanitizer(maxBytes: 4))->sanitizeString('€€') === '€', 'UTF-8 byte bound cut a code point.');

    $security = new SecurityPolicyMiddleware(hstsIncludeSubdomains: false, acceptCh: ['Sec-CH-UA'], timingAllowOrigins: ['https://client.example.test']);
    foreach (['http', 'https'] as $scheme) {
        $incoming = Request::fake(uri: "$scheme://lab.example.test/orders");
        $response = $security($incoming, static fn(): Response => Response::plaintext('ready')->withHeader('Content-Security-Policy', "default-src 'none'"));
        verify($response->getHeaderLine('Strict-Transport-Security') === ($scheme === 'https' ? 'max-age=31536000' : ''), 'HSTS crossed scheme boundary.');
        verify($response->getHeaderLine('Content-Security-Policy') === "default-src 'none'" && $response->getHeaderLine('X-Content-Type-Options') === 'nosniff' && $response->getHeaderLine('Accept-CH') === 'Sec-CH-UA' && $response->getHeaderLine('Timing-Allow-Origin') === 'https://client.example.test', 'Security policy defaults or preservation mismatch.');
    }
    echo "Maintenance publication/recovery, 24 input policy combinations and scheme-aware response protection passed\n";
}

function varyLinterWorkload(): void
{
    $vary = new VaryAccumulatorMiddleware();
    $linter = new ResponseLinterMiddleware(true);
    $request = Request::fake(method: 'OPTIONS', headers: ['Access-Control-Request-Method' => 'GET', 'Access-Control-Request-Headers' => 'X-Client']);
    $response = $vary($request, static function (Request $r): Response {
        $r = VaryAccumulatorMiddleware::add($r, 'accept', 'ACCEPT', 'X-Client');
        verify(VaryAccumulatorMiddleware::peek($r) === ['Accept', 'X-Client'], 'Vary context deduplication failed.');
        return new Response(200, 'abc', ['Content-Type' => 'text/plain', 'Content-Length' => '3', 'Vary' => 'accept, ACCEPT', 'Content-Encoding' => 'gzip', 'Content-Language' => 'en', 'Access-Control-Allow-Origin' => 'https://client.example.test']);
    });
    $tokens = array_map(static fn(string $token): string => strtolower(trim($token)), explode(',', $response->getHeaderLine('Vary')));
    sort($tokens);
    verify($tokens === ['accept', 'accept-encoding', 'accept-language', 'access-control-request-headers', 'access-control-request-method', 'origin', 'x-client'], 'Vary composition lost requirements.');
    verify($linter($request, static fn(): Response => $response) === $response, 'Valid composed response rejected.');
    $star = $vary(Request::fake(), static function (Request $r): Response {
        VaryAccumulatorMiddleware::add($r, 'Origin');
        return new Response(200, '', ['Vary' => '*']);
    });
    verify($star->getHeaderLine('Vary') === '*', 'Wildcard variation overwritten.');
    verify($vary(Request::fake(), static fn(): Response => new Response(200))->getHeaderLine('Vary') === '', 'Vary leaked to next request.');

    $get = Request::fake(method: 'GET');
    $head = Request::fake(method: 'HEAD');
    $valid = [
        [$get, new Response(200, 'abc', ['Content-Type' => 'text/plain', 'Content-Length' => '3'])],
        [$head, new Response(200, '', ['Content-Length' => '100'])],
        [$get, new Response(304, '', ['Content-Length' => '100'])],
        [$get, new Response(205, '', ['Content-Length' => '0'])],
        [$get, new Response(204)],
        [$get, new Response(200, 'abc', ['Content-Type' => 'text/plain', 'Content-Encoding' => 'gzip', 'Vary' => 'Accept-Encoding', 'ETag' => '"encoded"'])],
    ];
    foreach ($valid as [$r, $res]) {
        verify($linter($r, static fn(): Response => $res) === $res, 'Valid response rejected.');
    }
    foreach ([
        new Response(200, 'abc'),
        new Response(204, 'abc'),
        new Response(204, '', ['Content-Length' => '0']),
        new Response(205, '', ['Content-Length' => '2']),
        new Response(200, 'abc', ['Content-Type' => 'text/plain', 'Content-Length' => '2']),
        new Response(200, 'abc', ['Content-Type' => 'text/plain', 'Content-Length' => 'invalid']),
        new Response(200, 'abc', ['Content-Type' => 'text/plain', 'Content-Encoding' => 'gzip']),
    ] as $invalid) {
        expectFailure(static fn() => $linter($get, static fn(): Response => $invalid), RuntimeException::class, 'Linter accepted invalid response.');
        verify((new ResponseLinterMiddleware(false))($get, static fn(): Response => $invalid) === $invalid, 'Disabled linter altered response.');
    }
    echo "Vary composition and isolation, strict response linter and legal HEAD/304/205/encoded ETag cases passed\n";
}
