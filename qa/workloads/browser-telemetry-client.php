<?php

declare(strict_types=1);

namespace Experiment;

require getenv('PROJECT') . '/vendor/autoload.php';

$mode = (string) getenv('LAB_BROWSER_POLICY');
$root = (string) getenv('PROJECT');
$base = (string) getenv('LAB_HTTP_URL');
$prefix = bin2hex(random_bytes(8));
$trace = '0123456789abcdef0123456789abcdef';
$parent = '0123456789abcdef';
$spanIds = [];
$expected = [];
$logPath = $root . '/storage/lab-web-telemetry.jsonl';

function labTelemetryExchange(string $base, string $path, array $headers, int $status): array
{
    $curl = curl_init($base . $path . '?marker=synthetic-private-query');
    $received = [];
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $line) use (&$received): int {
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
    ensure(is_string($body) && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === $status, 'Unexpected telemetry HTTP status on ' . $path);
    ensure(!str_contains($body, 'synthetic-private-'), 'Sensitive fixture detail appeared in HTTP response.');

    return [json_decode($body, true, flags: JSON_THROW_ON_ERROR), array_map(static fn(array $values): string => implode(',', $values), $received)];
}

for ($i = 0; $i < 12; ++$i) {
    $id = $prefix . '-' . $i;
    $requestId = 'lab-' . $id;
    $headers = [
        'Accept: application/json',
        'X-Request-Id: ' . $requestId,
        'traceparent: 00-' . $trace . '-' . $parent . '-01',
        'tracestate: lab=one',
        'Authorization: Bearer synthetic-private-auth',
        'Cookie: lab=synthetic-private-cookie',
    ];
    if ($mode === 'errors') {
        [$unavailable, $responseHeaders] = labTelemetryExchange($base, '/lab/telemetry/unavailable/' . $id, $headers, 503);
        ensure($unavailable['id'] === $id && $unavailable['trace'] === $trace
            && $responseHeaders['trace-id'] === $trace && $responseHeaders['x-request-id'] === $requestId
            && $responseHeaders['cache-control'] === 'no-store', '503 trace or cache policy differed.');
        $expected[$id]['unavailable'] = true;
        [$problem, $errorHeaders] = labTelemetryExchange($base, '/lab/telemetry/failure/' . $id, [
            'Accept: application/problem+json', ...array_slice($headers, 1),
        ], 500);
        ensure($problem['status'] === 500 && $problem['request_id'] === $requestId
            && $problem['instance'] === '/lab/telemetry/failure/' . $id, 'Compiled error rendering lost correlation.');
        ensure(str_contains($errorHeaders['cache-control'] ?? '', 'no-store'), 'Error response was cacheable.');
        $expected[$id]['failure'] = true;
    }
    $invalid = $mode === 'trace' && $i % 2 !== 0;
    if ($invalid) {
        $headers[1] = 'X-Request-Id: bad id';
        $headers[2] = 'traceparent: 00-' . str_repeat('0', 32) . '-' . $parent . '-01';
        $headers[3] = 'tracestate: foreign=discard';
    }
    [$body, $responseHeaders] = labTelemetryExchange($base, '/lab/telemetry/trace/' . $id, $headers, 200);
    ensure($body['id'] === $id && $body['pid'] > 0 && $body['request_id'] === $responseHeaders['x-request-id']
        && $body['trace'] === $responseHeaders['trace-id'], 'Route and response correlation diverged.');
    if ($invalid) {
        ensure(preg_match('/^[0-9a-f]{32}$/D', $body['request_id']) === 1 && $body['trace'] !== $trace
            && $body['trace'] !== str_repeat('0', 32) && ($responseHeaders['tracestate'] ?? '') === '', 'Invalid incoming context was retained.');
    } else {
        ensure($body['request_id'] === $requestId && $body['trace'] === $trace && $body['parent'] === $parent
            && $body['sampled'] === true && $responseHeaders['tracestate'] === 'lab=one', 'Valid incoming context was lost.');
    }
    ensure(preg_match('/^[0-9a-f]{16}$/D', $body['span']) === 1 && $body['span'] !== $parent
        && !isset($spanIds[$body['span']]), 'Request span was reused.');
    $spanIds[$body['span']] = true;
    ensure(str_starts_with($responseHeaders['traceparent'], '00-' . $body['trace'] . '-' . $body['span'] . '-')
        && str_contains($responseHeaders['server-timing'], 'app;dur=')
        && isset($responseHeaders['x-response-time']), 'Emitted propagation or timing differs.');
    $nel = json_decode($responseHeaders['nel'], true, flags: JSON_THROW_ON_ERROR);
    ensure($nel['group'] === 'lab' && $nel['max_age'] === 300 && $nel['success_fraction'] === 0, 'NEL policy differs.');
    $expected[$id]['trace'] = $body;
}

// The application logger writes before the response is committed. Ignore
// unrelated concurrent request IDs, but require every owned event exactly once.
$raw = (string) file_get_contents($logPath);
ensure(!str_contains($raw, 'synthetic-private-'), 'Configured file logger leaked sensitive detail.');
$seen = [];
foreach (explode("\n", trim($raw)) as $line) {
    $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    $context = $record['context'];
    $id = $context['id'] ?? null;
    if (is_string($id) && isset($expected[$id])) {
        if ($record['message'] === 'lab-web-trace') {
            ensure($context['authorization'] === '[REDACTED]' && $context['nested']['cookie'] === '[REDACTED]'
                && $context['span'] === $expected[$id]['trace']['span'], 'Log redaction or span correlation differed.');
            $seen[$id]['trace'] = ($seen[$id]['trace'] ?? 0) + 1;
        } elseif ($record['message'] === 'lab-web-unavailable') {
            ensure($context['trace'] === $trace, 'Unavailable event trace differed.');
            $seen[$id]['unavailable'] = ($seen[$id]['unavailable'] ?? 0) + 1;
        }
    }
    if (($context['status'] ?? null) === 500 && isset($context['path']) && str_starts_with($context['path'], '/lab/telemetry/failure/')) {
        $id = basename($context['path']);
        if (isset($expected[$id]['failure'])) {
            ensure($context['request_id'] === 'lab-' . $id && $context['exception']['class'] === \RuntimeException::class
                && !isset($context['exception']['message'], $context['exception']['trace']), 'Exception logging policy differed.');
            $seen[$id]['failure'] = ($seen[$id]['failure'] ?? 0) + 1;
        }
    }
}
foreach ($expected as $id => $events) {
    foreach (array_keys($events) as $kind) {
        ensure(($seen[$id][$kind] ?? 0) === 1, 'Missing or duplicate persisted event: ' . $kind . ' ' . $id);
    }
}
echo json_encode(['mode' => $mode, 'host' => getenv('HOST_MODE'), 'matcher' => getenv('LAB_MATCHER'), 'verified' => count($expected)], JSON_THROW_ON_ERROR), PHP_EOL;
