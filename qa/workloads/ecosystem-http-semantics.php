<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\CacheValidatorsMiddleware;
use Infocyph\Webrick\Middleware\CompressionMiddleware;
use Infocyph\Webrick\Middleware\InputSanitizerMiddleware;
use Infocyph\Webrick\Middleware\NegotiationMiddleware;
use Infocyph\Webrick\Middleware\ResponseLinterMiddleware;
use Infocyph\Webrick\Middleware\SecurityPolicyMiddleware;
use Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware;
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;

function httpRepresentationsWorkload(): void
{
    $body = str_repeat('laboratory representation ', 1000);
    $handler = static fn(Request $req): Response => new Response(200, $body, ['Content-Type' => 'text/plain', 'ETag' => '"v1"']);
    $vary = new VaryAccumulatorMiddleware();
    $security = new SecurityPolicyMiddleware();
    $negotiation = new NegotiationMiddleware(produces: ['text/plain', 'application/json'], locales: ['en', 'bn']);
    $linter = new ResponseLinterMiddleware(true);
    foreach ([CompressionMiddleware::ETAG_WEAK_ON_ENCODE, CompressionMiddleware::ETAG_STRONG_DERIVE, CompressionMiddleware::ETAG_STRONG_RECOMP] as $mode) {
        $compression = new CompressionMiddleware(minBytes: 1, prefOrder: ['gzip'], etagMode: $mode);
        $request = Request::fake(headers: ['Accept' => 'text/plain', 'Accept-Language' => 'bn', 'Accept-Encoding' => 'gzip'], uri: 'https://lab.example/report');
        $response = $linter($request, static fn(Request $r): Response => $vary($r, static fn(Request $r): Response => $security($r, static fn(Request $r): Response => $negotiation($r, static fn(Request $r): Response => $compression($r, $handler)))));
        verify($response->getHeaderLine('Content-Encoding') === 'gzip' && gzdecode((string) $response->getBody()) === $body, 'Compressed body mismatch.');
        verify($response->getHeaderLine('Content-Language') === 'bn' && $response->hasHeader('Strict-Transport-Security') && $response->hasHeader('Content-Security-Policy'), 'Negotiation or security headers lost.');
        $tokens = array_map(static fn(string $token): string => strtolower(trim($token)), explode(',', $response->getHeaderLine('Vary')));
        verify(in_array('accept-encoding', $tokens, true) && in_array('accept-language', $tokens, true) && count(array_unique($tokens)) === count($tokens), 'Vary tokens lost or duplicated.');
        $etag = $response->getHeaderLine('ETag');
        verify($etag !== '' && ($mode === CompressionMiddleware::ETAG_WEAK_ON_ENCODE ? str_starts_with($etag, 'W/') : !str_starts_with($etag, 'W/')), 'ETag coding contract failed.');
    }
    $calls = 0;
    $conditional = new CacheValidatorsMiddleware(metaProvider: static fn(Request $r): array => ['"v1"', 1_700_000_000, true]);
    $next = static function (Request $request) use (&$calls): Response {
        ++$calls;
        return new Response(200, 'updated', ['Content-Type' => 'text/plain']);
    };
    verify($conditional(Request::fake(headers: ['If-None-Match' => '"v1"']), $next)->getStatusCode() === 304 && $calls === 0, 'Conditional GET dispatched unexpectedly.');
    verify($conditional(Request::fake(method: 'PUT', headers: ['If-Match' => '"stale"']), $next)->getStatusCode() === 412 && $calls === 0, 'Stale mutation was dispatched.');
    verify($conditional(Request::fake(method: 'PUT', headers: ['If-Match' => '"v1"']), $next)->getStatusCode() === 200 && $calls === 1, 'Matching mutation failed.');
    verifyHttpStatus(static fn() => $negotiation(Request::fake(headers: ['Accept' => 'image/png']), $handler), 406);
    expectFailure(static fn() => $linter(Request::fake(), static fn(Request $r): Response => new Response(200, 'abc', ['Content-Type' => 'text/plain', 'Content-Length' => '99'])), RuntimeException::class, 'Response linter accepted wrong byte count.');
    $normalizer = new InputSanitizerMiddleware(touchQuery: true, touchJsonBodies: true);
    $input = Request::fake(method: 'POST', headers: ['Content-Type' => 'application/json'])->withQueryParams(['search' => ' padded '])->withParsedBody(['name' => ' Alice ']);
    $normalized = $normalizer($input, static fn(Request $r): Response => Response::json([$r->getQueryParams(), $r->getParsedBody()]));
    verify(json_decode((string) $normalized->getBody(), true, flags: JSON_THROW_ON_ERROR) === [['search' => 'padded'], ['name' => 'Alice']], 'Opt-in normalization failed.');
    echo "Compression/ETag, conditional mutation, negotiation, Vary, security and normalization passed\n";
}

function httpSignaturesWorkload(): void
{
    $old = bin2hex(random_bytes(32));
    $new = bin2hex(random_bytes(32));
    $config = new SignedUrlConfig(verificationKeys: [$new, $old], payloadMode: SignedUrlConfig::MODE_ABSOLUTE, ignoredQueryParams: ['tracking']);
    $verify = new VerifySignedUrlMiddleware($config);
    $next = static fn(Request $request): Response => new Response(200, 'accepted');
    $make = static function (string $key, int $expiry, string $host = 'lab.example', string $document = '42'): Request {
        $query = ['_exp' => $expiry, 'document' => $document];
        $payload = 'https://' . $host . '/download?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $query['_sig'] = hash_hmac('sha3-256', $payload, $key);
        return Request::fake(uri: 'https://' . $host . '/download')->withQueryParams($query);
    };
    foreach ([$old, $new] as $key) {
        $request = $make($key, time() + 300);
        verify($verify($request, $next)->getStatusCode() === 200, 'Key rotation rejected valid signature.');
        $query = $request->getQueryParams();
        verify($verify($request->withQueryParams($query + ['tracking' => 'ignored']), $next)->getStatusCode() === 200, 'Ignored query parameter changed signature.');
        verifyHttpStatus(static fn() => $verify($request->withQueryParams(array_replace($query, ['document' => '43'])), $next), 403);
        verifyHttpStatus(static fn() => $verify(Request::fake(uri: 'https://foreign.example/download')->withQueryParams($query), $next), 403);
        verifyHttpStatus(static fn() => $verify($request->withQueryParams(['document' => '42']), $next), 400);
    }
    verifyHttpStatus(static fn() => $verify($make($new, time() - 10), $next), 410);
    echo "Signed absolute URL, host/query tamper, expiration and rotating verification keys passed\n";
}
