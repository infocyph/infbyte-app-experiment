<?php

declare(strict_types=1);

namespace Experiment;

require getenv('PROJECT') . '/vendor/autoload.php';

$base = getenv('LAB_HTTP_URL');
$temporary = getenv('RUNNER_TEMP');
ensure(is_string($base) && is_string($temporary), 'Missing isolated browser fixture configuration.');
$fixture = $temporary . '/browser-session.json';
$curl = curl_init();
ensure($curl instanceof \CurlHandle, 'Cannot initialize browser HTTP client.');

/**
 * @param list<string> $headers
 * @return array{body:string,headers:array<string,list<string>>}
 */
function exchange(\CurlHandle $curl, string $base, string $method, string $path, int $status, array $headers = [], string $cookie = ''): array
{
    $received = [];
    curl_setopt_array($curl, [
        CURLOPT_URL => $base . $path,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIE => $cookie,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$received): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $received[strtolower(trim($parts[0]))][] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);
    $body = curl_exec($curl);
    $actual = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    ensure(is_string($body) && $actual === $status, sprintf('%s %s expected %d, got %d', $method, strtok($path, '?'), $status, $actual));

    return ['body' => $body, 'headers' => $received];
}

/** @return array<string,mixed> */
function payload(array $result): array
{
    $decoded = json_decode($result['body'], true, flags: JSON_THROW_ON_ERROR);
    ensure(is_array($decoded), 'Expected JSON object.');

    return $decoded;
}

function sessionCookie(array $result): string
{
    foreach ($result['headers']['set-cookie'] ?? [] as $value) {
        if (!str_starts_with($value, 'lab_session=')) {
            continue;
        }
        ensure(preg_match('/(?:^|;\s*)Secure(?:;|$)/i', $value) === 1
            && preg_match('/(?:^|;\s*)HttpOnly(?:;|$)/i', $value) === 1
            && preg_match('/(?:^|;\s*)SameSite=Strict(?:;|$)/i', $value) === 1, 'Unsafe browser session cookie attributes.');

        return explode(';', $value, 2)[0];
    }
    throw new \RuntimeException('Session cookie missing.');
}

/** @return list<string> */
function csrf(string $token, string $origin = 'https://experiment.test'): array
{
    return ['X-CSRF-Token: ' . $token, 'Origin: ' . $origin, 'Accept: application/json'];
}

$mode = $argv[1] ?? '';
if ($mode === 'initialize') {
    $result = exchange($curl, $base, 'GET', '/lab/session', 200);
    $cookie = sessionCookie($result);
    $state = payload($result);
    ensure($state['count'] === 0 && is_string($state['csrf']) && strlen($state['csrf']) === 64, 'Unexpected initial session state.');
    $csrf = $state['csrf'];
    exchange($curl, $base, 'POST', '/lab/session/add', 419, cookie: $cookie);
    exchange($curl, $base, 'POST', '/lab/session/add', 419, csrf($csrf, 'https://foreign.example.test'), $cookie);
    exchange($curl, $base, 'POST', '/lab/session/add?_token=' . rawurlencode($csrf), 419, cookie: $cookie);
    $other = exchange($curl, $base, 'GET', '/lab/session', 200);
    $otherCookie = sessionCookie($other);
    ensure($otherCookie !== $cookie && payload($other)['csrf'] !== $csrf, 'Separate browser identities shared session state.');
    exchange($curl, $base, 'POST', '/lab/session/add', 419, csrf($csrf), $otherCookie);
    ensure(file_put_contents($fixture, json_encode(['cookie' => $cookie, 'csrf' => $csrf], JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'Session fixture write failed.');
    ensure(chmod($fixture, 0600), 'Session fixture permissions failed.');

    return;
}
$session = json_decode(file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR);
$cookie = $session['cookie'];
$csrf = $session['csrf'];
if ($mode === 'worker') {
    $worker = (int) $argv[2];
    for ($i = 0; $i < (int) $argv[3]; ++$i) {
        $start = hrtime(true);
        $response = payload(exchange($curl, $base, 'POST', '/lab/session/add', 200, csrf($csrf), $cookie));
        ensure(is_int($response['count'] ?? null) && $response['count'] > 0, 'Cart increment response invalid.');
        echo json_encode(['id' => $worker . '-' . $i, 'milliseconds' => (hrtime(true) - $start) / 1e6], JSON_THROW_ON_ERROR), PHP_EOL;
    }

    return;
}
ensure($mode === 'verify', 'Unknown browser mode.');
$expected = (int) $argv[2];
$state = payload(exchange($curl, $base, 'GET', '/lab/session', 200, cookie: $cookie));
ensure($state['count'] === $expected, 'Lost concurrent browser session updates.');
$rotated = exchange($curl, $base, 'POST', '/lab/session/rotate', 200, csrf($csrf), $cookie);
$nextCookie = sessionCookie($rotated);
$next = payload($rotated);
ensure($nextCookie !== $cookie && $next['count'] === $expected, 'Session rotation did not preserve state.');
$old = payload(exchange($curl, $base, 'GET', '/lab/session', 200, cookie: $cookie));
ensure($old['count'] === 0 && $old['csrf'] !== $csrf, 'Old session remained active.');
exchange($curl, $base, 'POST', '/lab/session/flash', 204, csrf($next['csrf']), $nextCookie);
ensure(payload(exchange($curl, $base, 'GET', '/lab/session', 200, cookie: $nextCookie))['flash'] === 'Saved', 'Flash missing.');
ensure(payload(exchange($curl, $base, 'GET', '/lab/session', 200, cookie: $nextCookie))['flash'] === null, 'Flash replay accepted.');
$invalidated = exchange($curl, $base, 'POST', '/lab/session/invalidate', 200, csrf($next['csrf']), $nextCookie);
ensure(sessionCookie($invalidated) !== $nextCookie && payload($invalidated)['count'] === 0, 'Session invalidation failed.');

ensure(payload(exchange($curl, $base, 'GET', '/lab/items/42', 200)) === ['item' => 42], 'Typed route mismatch.');
ensure(payload(exchange($curl, $base, 'GET', '/lab/items/new', 200)) === ['item' => 'new'], 'Static route precedence failed.');
exchange($curl, $base, 'GET', '/lab/items/not-an-int', 404);
$wrongMethod = exchange($curl, $base, 'DELETE', '/lab/items/42', 405);
ensure(str_contains(implode(',', $wrongMethod['headers']['allow'] ?? []), 'GET'), '405 lacks Allow.');
exchange($curl, $base, 'OPTIONS', '/lab/items/42', 204);
ensure(exchange($curl, $base, 'HEAD', '/lab/items/42', 200)['body'] === '', 'HEAD returned body.');
ensure(payload(exchange($curl, $base, 'GET', '/lab/negotiate', 200, ['Accept: application/json'])) === ['item' => 'report'], 'JSON negotiation failed.');
exchange($curl, $base, 'GET', '/lab/negotiate', 406, ['Accept: image/png']);
$compressed = exchange($curl, $base, 'GET', '/lab/compress', 200, ['Accept-Encoding: gzip']);
ensure(($compressed['headers']['content-encoding'][0] ?? '') === 'gzip', 'Gzip not applied.');
ensure(gzdecode($compressed['body']) === str_repeat("report-line\n", 1024), 'Compressed response bytes mismatch.');
ensure(str_contains(strtolower(implode(',', $compressed['headers']['vary'] ?? [])), 'accept-encoding'), 'Compression missing Vary.');
$plain = exchange($curl, $base, 'GET', '/lab/compress', 200, ['Accept-Encoding: identity']);
$etag = $plain['headers']['etag'][0] ?? null;
ensure(is_string($etag), 'Missing generated validator.');
ensure(exchange($curl, $base, 'GET', '/lab/compress', 304, ['Accept-Encoding: identity', 'If-None-Match: ' . $etag])['body'] === '', '304 response contained a body.');
$asset = exchange($curl, $base, 'GET', '/lab/assets/report.bin', 206, ['Range: bytes=16-31']);
ensure($asset['body'] === '0123456789abcdef' && ($asset['headers']['content-range'][0] ?? '') === 'bytes 16-31/131072', 'Partial file response mismatch.');
exchange($curl, $base, 'GET', '/lab/assets/report.bin', 416, ['Range: bytes=200000-']);
exchange($curl, $base, 'GET', '/lab/assets/missing.bin', 404);
$stream = exchange($curl, $base, 'GET', '/lab/stream', 200);
$lines = explode("\n", rtrim($stream['body'], "\n"));
ensure(count($lines) === 128, 'Streaming line count mismatch.');
foreach ($lines as $i => $line) {
    ensure(json_decode($line, true, flags: JSON_THROW_ON_ERROR) === ['line' => $i + 1], 'Streaming ordering mismatch.');
}
unlink($fixture);
echo "Concurrent session and HTTP protocol invariants verified.\n";
