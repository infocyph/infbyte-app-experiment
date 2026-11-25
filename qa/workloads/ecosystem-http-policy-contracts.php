<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\TalkingBytes\Http\Cookie\CookieJar;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\HttpResponse;
use Infocyph\Webrick\Middleware\CacheValidatorsMiddleware;
use Infocyph\Webrick\Middleware\CorsMiddleware;
use Infocyph\Webrick\Middleware\ResponseCacheMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;

function responseCacheFreshnessContracts(string $backend): void
{
    [$store, $peer] = cacheContractPeers($backend, 'response-freshness');
    $a = new ResponseCacheMiddleware(store: $store, ttlSeconds: 60);
    $b = new ResponseCacheMiddleware(store: $peer, ttlSeconds: 60);
    $calls = 0;
    $origin = static function (Request $request) use (&$calls): Response {
        return new Response(200, 'report-' . ++$calls, ['Cache-Control' => 'public, max-age=300, s-maxage=1', 'Content-Type' => 'text/plain']);
    };
    $uri = 'https://lab.example/report';
    $get = Request::fake(uri: $uri);
    $head = Request::fake(method: 'HEAD', uri: $uri);
    verify($a($head, $origin)->getStatusCode() === 200 && $calls === 1, 'Cold HEAD did not dispatch.');
    $first = $a($get, $origin);
    verify($first->getStringBody() === 'report-2' && $calls === 2, 'Cold HEAD poisoned GET.');
    $cached = $b($head, $origin);
    verify($cached->getStringBody() === '' && (int) $cached->getHeaderLine('Content-Length') === 8 && $calls === 2, 'Warm HEAD did not preserve GET length or dispatched origin.');
    verify($b($get, $origin)->getStringBody() === 'report-2', 'Independent store missed warm GET.');
    $deadline = hrtime(true) + 8_000_000_000;
    do {
        usleep(50_000);
        verify(hrtime(true) < $deadline, 'Shared max-age did not expire.');
        $fresh = $b($get, $origin);
    } while ($fresh->getStringBody() === 'report-2');
    verify($fresh->getStringBody() === 'report-3' && $calls === 3, 'Fresh generation did not reconcile.');
    $agedCalls = 0;
    $aged = static function (Request $r) use (&$agedCalls): Response {
        return new Response(200, (string) ++$agedCalls, ['Cache-Control' => 'public, s-maxage=30', 'Age' => '30']);
    };
    $agedRequest = Request::fake(uri: 'https://lab.example/aged');
    verify($a($agedRequest, $aged)->getStringBody() === '1' && $b($agedRequest, $aged)->getStringBody() === '2', 'Already stale representation was stored.');
    echo "Response cache $backend HEAD, shared freshness and stale Age boundaries passed\n";
}

function responseCacheBypassContracts(string $backend): void
{
    [$store, $peer] = cacheContractPeers($backend, 'response-bypass');
    $a = new ResponseCacheMiddleware(store: $store, maxBodyBytes: 32);
    $b = new ResponseCacheMiddleware(store: $peer, maxBodyBytes: 32);
    $calls = 0;
    $handler = static function (Request $r) use (&$calls): Response {
        return new Response(200, (string) ++$calls, ['Cache-Control' => 'public, max-age=60']);
    };
    $uri = 'https://lab.example/public';
    $request = Request::fake(uri: $uri);
    verify($a($request, $handler)->getStringBody() === '1', 'Initial cache seed failed.');
    foreach ([
        ['Range' => 'bytes=0-1'], ['If-None-Match' => '"v1"'], ['If-Match' => '*'],
        ['Cache-Control' => 'no-cache'], ['Cache-Control' => 'no-store'],
        ['Cache-Control' => 'max-age=0'], ['Pragma' => 'no-cache'],
    ] as $headers) {
        $before = $calls;
        verify($b(Request::fake(headers: $headers, uri: $uri), $handler)->getStringBody() === (string) ($before + 1), 'Request directive bypass failed.');
        verify($a($request, $handler)->getStringBody() === '1', 'Bypassed request poisoned cached GET.');
    }
    verify($b($request->withAttribute('personalized', true), $handler)->getStringBody() !== '1', 'Personalized attribute bypass failed.');
    foreach ([
        ['Vary' => '*'], ['Vary' => 'X-Unkeyed'], ['Set-Cookie' => 'session=private'],
        ['Cache-Control' => 'no-cache'], ['Age' => 'invalid'],
    ] as $index => $headers) {
        $test = Request::fake(uri: 'https://lab.example/reject-' . $index);
        $next = static function (Request $r) use ($headers, &$calls): Response {
            return new Response(200, (string) ++$calls, $headers + ['Cache-Control' => 'public, max-age=60']);
        };
        verify($a($test, $next)->getStringBody() !== $b($test, $next)->getStringBody(), 'Unsafe response was shared.');
    }
    $large = Request::fake(uri: 'https://lab.example/large');
    $before = $calls;
    $oversized = static function (Request $r) use (&$calls): Response {
        ++$calls;
        return new Response(200, str_repeat('x', 33));
    };
    $a($large, $oversized);
    $b($large, $oversized);
    verify($calls === $before + 2 && $a($request, $handler)->getStringBody() === '1', 'Body limit or shared cache isolation failed.');
    echo "Response cache $backend request/response bypass, Vary, cookie and size boundaries passed\n";
}

function conditionalPrecedenceContracts(): void
{
    $modified = 1_700_000_000;
    $date = static fn(int $time): string => gmdate('D, d M Y H:i:s', $time) . ' GMT';
    $middleware = new CacheValidatorsMiddleware(metaProvider: static fn(Request $r): array => ['"v2"', $modified, true]);
    foreach ([
        ['GET', ['If-None-Match' => 'W/"v2"'], 304],
        ['PUT', ['If-None-Match' => 'W/"v2"'], 412],
        ['PUT', ['If-Match' => 'W/"v2"'], 412],
        ['PUT', ['If-Match' => '"v2"', 'If-Unmodified-Since' => $date($modified - 1)], 200],
        ['GET', ['If-None-Match' => '"old"', 'If-Modified-Since' => $date($modified + 1)], 200],
        ['HEAD', ['If-Modified-Since' => $date($modified)], 304],
        ['PATCH', ['If-Unmodified-Since' => $date($modified - 1)], 412],
    ] as [$method, $headers, $expected]) {
        $calls = 0;
        $next = static function (Request $r) use (&$calls): Response {
            ++$calls;
            return new Response(200, 'current');
        };
        $result = $middleware(Request::fake(method: $method, headers: $headers), $next);
        verify($result->getStatusCode() === $expected && $calls === (int) ($expected === 200), 'Conditional precedence or side effect boundary failed.');
    }
    $absent = new CacheValidatorsMiddleware(metaProvider: static fn(Request $r): array => [null, null, false]);
    verify($absent(Request::fake(method: 'PUT', headers: ['If-Match' => '*']), static fn() => throw new LogicException('Mutation reached.'))->getStatusCode() === 412, 'Missing representation matched wildcard.');
    verify($absent(Request::fake(method: 'PUT', headers: ['If-None-Match' => '*']), static fn() => new Response(201, 'created'))->getStatusCode() === 201, 'Creation precondition rejected absence.');
    foreach (['"v2"' => true, 'W/"v2"' => false, '"old"' => false, $date($modified) => true] as $validator => $fresh) {
        $middleware(Request::fake(headers: ['Range' => 'bytes=0-1', 'If-Range' => $validator]), static function (Request $r) use ($fresh): Response {
            verify($r->hasHeader('Range') === $fresh && ($r->getAttribute('range_dropped') === true) === !$fresh, 'If-Range disposition differs.');
            return new Response(200, 'current');
        });
    }
    echo "Conditional precedence, weak/strong comparison, creation and range boundaries passed\n";
}

function routeCorsContracts(): void
{
    $middleware = new CorsMiddleware(origins: ['https://global.example.test']);
    $policy = new Cors(['https://*.client.example.test'], methods: 'POST', headers: ['X-Order'], exposeHeaders: ['X-Receipt'], allowCredentials: true, allowPrivateNetwork: true);
    $calls = 0;
    $next = static function (Request $r) use (&$calls): Response {
        ++$calls;
        return new Response(200, 'ok');
    };
    $request = static fn(string $origin, string $header = 'X-Order'): Request => Request::fake(method: 'OPTIONS', headers: [
        'Origin' => $origin, 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => $header,
        'Access-Control-Request-Private-Network' => 'true',
    ])->withAttribute('cors_policy', $policy);
    $origin = 'https://one.client.example.test';
    $preflight = $middleware($request($origin), $next);
    verify($preflight->getStatusCode() === 204 && $preflight->getHeaderLine('Access-Control-Allow-Origin') === $origin && $preflight->getHeaderLine('Access-Control-Allow-Private-Network') === 'true' && $preflight->getHeaderLine('Access-Control-Allow-Credentials') === 'true', 'Route policy preflight differs.');
    foreach (['https://global.example.test', 'https://client.example.test', 'https://one.client.example.test.evil.test', 'http://one.client.example.test', 'https://one.client.example.test:444'] as $foreign) {
        verifyHttpStatus(static fn() => $middleware($request($foreign), $next), 403);
    }
    verifyHttpStatus(static fn() => $middleware($request($origin, 'X-Foreign'), $next), 403);
    verify($calls === 0, 'Preflight reached application.');
    $actual = $middleware(Request::fake(headers: ['Origin' => $origin])->withAttribute('cors_policy', $policy), $next);
    verify($actual->getHeaderLine('Access-Control-Expose-Headers') === 'X-Receipt' && $calls === 1, 'Actual response lost route metadata.');
    echo "Route CORS override, wildcard/scheme/port, private-network preflight and header boundaries passed\n";
}

function outboundCookieContracts(): void
{
    $jar = new CookieJar(maxCookies: 16, allowDomainCookies: true, allowedParentDomains: ['example.test']);
    $url = 'https://shop.example.test/orders/1';
    $store = static function (array $lines, string $origin) use ($jar): void {
        $jar->storeFromResponse(new HttpResponse(200, '', ['Set-Cookie' => $lines]), $origin);
    };
    $header = static fn(string $origin): string => (string) ($jar->applyToRequest(HttpRequest::get($origin))->headers->get('Cookie') ?? '');
    $store(['__Host-session=secure; Secure; Path=/', 'cart=base; Secure; Path=/', 'cart=orders; Secure; Path=/orders', 'shared=ok; Domain=example.test; Secure; Path=/'], $url);
    verify($jar->count() === 4 && str_contains($header($url), 'cart=orders') && !str_contains($header($url), 'cart=base'), 'Longest-path selection differs.');
    verify($header('https://other.example.test/') === 'shared=ok' && $header('http://shop.example.test/') === '', 'Domain/secure isolation failed.');
    $store(['__Host-bad=x; Secure; Path=/orders', '__Host-domain=x; Secure; Path=/; Domain=example.test', '__Secure-bad=x; Path=/', 'foreign=x; Domain=foreign.test; Secure'], $url);
    verify($jar->count() === 4, 'Invalid prefix or foreign domain accepted.');
    $store(['cart=overlay; Path=/orders', '__Host-session=overlay; Path=/'], 'http://shop.example.test/orders');
    verify(str_contains($header($url), 'cart=orders') && str_contains($header($url), '__Host-session=secure'), 'Insecure overlay modified secure cookie.');
    $explicit = $jar->applyToRequest(HttpRequest::get($url)->header('Cookie', 'cart=explicit'))->headers->get('Cookie');
    verify(is_string($explicit) && str_contains($explicit, 'cart=explicit') && !str_contains($explicit, 'cart=orders'), 'Explicit cookie precedence failed.');
    $store(['cart=gone; Secure; Path=/orders; Max-Age=0; Expires=Wed, 01 Jan 2099 00:00:00 GMT'], $url);
    verify(str_contains($header($url), 'cart=base') && !str_contains($header($url), 'cart=orders'), 'Max-Age deletion or path fallback differs.');
    echo "Outbound cookie security prefixes, domain/path scope, overlay and expiry passed\n";
}

function cancellationOwnershipContracts(): void
{
    $now = hrtime(true);
    $parent = new CancellationSource(RequestDeadline::afterSeconds(60, $now));
    $child = $parent->child(RequestDeadline::afterSeconds(30, $now));
    $linked = CancellationSource::linked($parent->token(), RequestDeadline::afterSeconds(120, $now));
    $detached = $parent->child();
    $detached->dispose();
    $calls = [];
    $removed = $child->token()->onCancel(static function () use (&$calls): void { $calls[] = 'removed'; });
    $removed->unsubscribe();
    $child->token()->onCancel(static function (CancellationToken $token) use (&$calls): void { $calls[] = $token->reason(); });
    $child->token()->onCancel(static fn() => throw new DomainException('observer isolation'));
    $child->token()->onCancel(static function () use (&$calls): void { $calls[] = 'last'; });
    verify($child->token()->deadline()->monotonicNanoseconds === $now + 30_000_000_000 && $linked->token()->deadline()->monotonicNanoseconds === $now + 60_000_000_000, 'Earliest deadline differs.');
    verify($parent->cancel(CancellationReason::HOST_CANCELLED) && !$parent->cancel(CancellationReason::WORKER_SHUTDOWN), 'Cancellation settled more than once.');
    verify($child->token()->reason() === CancellationReason::HOST_CANCELLED && $linked->token()->reason() === CancellationReason::HOST_CANCELLED && !$detached->token()->isCancelled() && $calls === [CancellationReason::HOST_CANCELLED, 'last'], 'Child/observer ownership differs.');
    $late = $parent->child();
    verify($late->token()->reason() === CancellationReason::HOST_CANCELLED, 'Late child lost terminal reason.');
    expectFailure(static fn() => $child->token()->throwIfCancelled(), CancelledException::class, 'Cancelled child did not throw.');
    $deadline = new CancellationSource(RequestDeadline::afterSeconds(5, $now));
    verify(!$deadline->token()->isCancelled($now + 4_999_999_999) && $deadline->token()->reason($now + 5_000_000_000) === CancellationReason::DEADLINE_EXCEEDED, 'Exact deadline boundary differs.');
    foreach ([$child, $linked, $late, $deadline, $parent] as $source) {
        $source->dispose();
    }
    echo "Structured cancellation, observer isolation, child disposal and exact deadlines passed\n";
}
