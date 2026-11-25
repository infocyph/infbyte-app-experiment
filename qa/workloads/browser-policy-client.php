<?php

declare(strict_types=1);

namespace Experiment;

require getenv('PROJECT') . '/vendor/autoload.php';

$base = (string) getenv('LAB_HTTP_URL');
$mode = (string) getenv('LAB_BROWSER_POLICY');
$curl = curl_init();
ensure($curl instanceof \CurlHandle && in_array($mode, ['cors', 'representation'], true), 'Invalid policy fixture.');

function policyExchange(\CurlHandle $curl, string $base, string $method, string $path, int $status, array $headers = []): array
{
    $received = [];
    curl_setopt_array($curl, [
        CURLOPT_URL => $base . $path,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$received): int {
            if (str_starts_with($line, 'HTTP/')) {
                $received = [];
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $received[strtolower(trim($parts[0]))][] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);
    $body = curl_exec($curl);
    $actual = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    ensure(is_string($body) && $actual === $status, sprintf('%s %s expected %d, got %d', $method, $path, $status, $actual));

    return ['body' => $body, 'headers' => $received];
}

$request = static fn(string $method, string $path, int $status, array $headers = []): array => policyExchange($curl, $base, $method, $path, $status, $headers);
$header = static fn(array $response, string $name): string => implode(',', $response['headers'][$name] ?? []);
$vary = static fn(array $response): array => array_map(static fn(string $token): string => strtolower(trim($token)), explode(',', $header($response, 'vary')));
$path = '/lab/policy/report';
$origin = 'https://client.example.test';
$valid = ['Origin: ' . $origin, 'Accept: text/plain', 'Accept-Charset: utf-8', 'Accept-Encoding: identity'];
$body = str_repeat("report-line\n", 1024);
$good = $request('GET', $path, 200, $valid);
ensure($good['body'] === $body && $header($good, 'etag') === '"lab-report-v1"', 'Canonical response differs.');
ensure($header($good, 'access-control-allow-origin') === $origin && $header($good, 'access-control-allow-credentials') === 'true', 'Route CORS policy missing.');
if ($mode === 'cors') {
    $preflight = ['Origin: ' . $origin, 'Access-Control-Request-Method: GET', 'Access-Control-Request-Headers: X-Lab-Request'];
    $allowed = $request('OPTIONS', $path, 204, $preflight);
    ensure($allowed['body'] === '' && $header($allowed, 'access-control-allow-origin') === $origin
        && $header($allowed, 'access-control-max-age') === '120', 'Preflight response differs.');
    foreach ([
        ['Origin: https://foreign.example.test', $preflight[1], $preflight[2]],
        [$preflight[0], 'Access-Control-Request-Method: DELETE', $preflight[2]],
        [$preflight[0], $preflight[1], 'Access-Control-Request-Headers: X-Foreign'],
    ] as $invalid) {
        $request('OPTIONS', $path, 403, $invalid);
    }
    $foreign = $request('GET', $path, 200, ['Origin: https://foreign.example.test', 'Accept: text/plain', 'Accept-Encoding: identity']);
    ensure($foreign['body'] === $body && $header($foreign, 'access-control-allow-origin') === '' && in_array('origin', $vary($foreign), true), 'Foreign origin leaked permission or Vary.');
    $request('GET', $path, 406, ['Origin: ' . $origin, 'Accept: image/png']);
    $request('GET', $path, 406, ['Origin: ' . $origin, 'Accept: text/plain', 'Accept-Charset: utf-8;q=0']);
    $unscoped = $request('GET', '/lab/policy/plain', 200, $valid);
    ensure($unscoped['body'] === $body && $header($unscoped, 'access-control-allow-origin') === '', 'Route policy escaped to unrelated route.');
    ensure($request('GET', $path, 200, $valid)['body'] === $body, 'Policy rejection damaged later request.');
} else {
    $gzipHeaders = [$valid[0], $valid[1], $valid[2], 'Accept-Encoding: gzip'];
    $encoded = $request('GET', $path, 200, $gzipHeaders);
    ensure($header($encoded, 'content-encoding') === 'gzip' && gzdecode($encoded['body']) === $body, 'Encoded bytes differ.');
    $tokens = $vary($encoded);
    ensure(in_array('origin', $tokens, true) && in_array('accept-encoding', $tokens, true) && count($tokens) === count(array_unique($tokens)), 'Composed Vary lost or duplicated tokens.');
    $etag = $header($encoded, 'etag');
    ensure($etag !== '', 'Encoded representation lost validator.');
    $cached = $request('GET', $path, 304, [...$gzipHeaders, 'If-None-Match: ' . $etag]);
    ensure($cached['body'] === '' && $header($cached, 'access-control-allow-origin') === $origin, 'Conditional encoded response lost CORS or returned body.');
    $identity = $request('GET', $path, 304, [...$valid, 'If-None-Match: "lab-report-v1"']);
    ensure($identity['body'] === '', 'Conditional identity response returned body.');
    $head = $request('HEAD', $path, 200, $valid);
    ensure($head['body'] === '' && $header($head, 'etag') === '"lab-report-v1"', 'HEAD lost metadata or returned body.');
    $asset = '/lab/policy/assets/report.bin';
    $range = $request('GET', $asset, 206, ['Range: bytes=16-31', 'Accept-Encoding: gzip']);
    ensure($range['body'] === '0123456789abcdef' && $header($range, 'content-range') === 'bytes 16-31/131072'
        && $header($range, 'content-encoding') === '', 'Range was transformed or corrupted.');
    $request('GET', $asset, 416, ['Range: bytes=200000-', 'Accept-Encoding: gzip']);
    $full = $request('GET', $asset, 200, ['Accept-Encoding: identity']);
    ensure(hash('sha256', $full['body']) === hash('sha256', str_repeat('0123456789abcdef', 8192)), 'Full file after range rejection differs.');
    $stream = $request('GET', '/lab/policy/stream', 200, ['Accept-Encoding: gzip']);
    $streamBody = $header($stream, 'content-encoding') === 'gzip' ? gzdecode($stream['body']) : $stream['body'];
    $expected = implode('', array_map(static fn(int $i): string => json_encode(['line' => $i], JSON_THROW_ON_ERROR) . "\n", range(1, 128)));
    ensure($streamBody === $expected, 'Stream ordering or compression changed bytes.');
}
echo json_encode(['policy' => $mode, 'host' => getenv('HOST_MODE'), 'matcher' => getenv('LAB_MATCHER'), 'verified' => true], JSON_THROW_ON_ERROR), PHP_EOL;
