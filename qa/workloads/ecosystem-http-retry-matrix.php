<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy;

function httpRetryMatrixContracts(string $group, string $contract): void
{
    $base = 'https://localhost:' . (int) getenv('LAB_HTTP_PORT');
    $ca = (string) getenv('LAB_HTTP_CA');
    $path = '/retry-matrix/' . $group . '/' . $contract;
    $method = $group === 'method' ? explode('-', $contract)[0] : 'get';
    if ($group === 'transport' && str_starts_with($contract, 'post-')) {
        $method = 'post';
    }
    $request = HttpRequest::$method($base . $path)->caBundle($ca)->timeout(5)->header('Connection', 'close');
    $payload = "binary\x00order\xff";
    $hasBody = in_array($method, ['put', 'post', 'patch'], true);
    if ($hasBody) {
        $request = $request->raw($payload, 'application/octet-stream');
    }
    if (str_ends_with($contract, '-keyed')) {
        $request = $request->header('Idempotency-Key', 'lab-operation');
    } elseif (str_ends_with($contract, '-opted')) {
        $request = $request->allowUnsafeMethodRetry();
    }
    $waits = [];
    $cancelled = false;
    $sleeper = new Sleeper(static function (int $microseconds) use (&$waits, &$cancelled, $group, $contract): void {
        $waits[] = $microseconds;
        if ($group === 'cancel' && $contract === 'during-wait') {
            $cancelled = true;
        }
    });
    $signal = $group === 'cancel'
        ? CancellationSignal::fromCallable(static function () use (&$cancelled, $contract): bool {
            return $contract === 'preflight' || $cancelled;
        })
        : null;
    $policy = new HttpRetryPolicy(maxAttempts: $contract === 'exhausted' ? 2 : 3, baseDelayMs: 10,
        maxRetryAfterSeconds: 2, retryOnTransportError: $contract !== 'get-disabled');
    $client = HttpClient::curl()->withHttpRetry($policy, cancellation: $signal, sleeper: $sleeper);
    $result = $client->send($request);
    $expected = match ($group) {
        'status' => [in_array((int) $contract, [408, 425, 429, 500, 502, 503, 504], true) ? 3 : 1, in_array((int) $contract, [408, 425, 429, 500, 502, 503, 504], true)],
        'method' => [in_array($contract, ['post-unsafe', 'patch-unsafe'], true) ? 1 : 3, !in_array($contract, ['post-unsafe', 'patch-unsafe'], true)],
        'delay' => [$contract === 'exhausted' ? 2 : 3, $contract !== 'exhausted'],
        'transport' => [in_array($contract, ['get-disabled', 'post-unsafe'], true) ? 1 : 3, !in_array($contract, ['get-disabled', 'post-unsafe'], true)],
        'cancel' => [$contract === 'preflight' ? 0 : 1, false],
        default => throw new InvalidArgumentException('Unknown retry group.'),
    };
    [$attempts, $success] = $expected;
    verify($result->successful === $success, 'Retry final result differs.');
    if ($group === 'cancel') {
        verify(($result->metadata['cancelled'] ?? false) === true && ($result->metadata['attempts'] ?? null) === $attempts, 'Cancellation lost attempt evidence.');
        verify($waits === ($contract === 'preflight' ? [] : [50000]), 'Cancellation wait was not interruptible.');
    } elseif ($group === 'transport' && !$success) {
        verify($result->response === null, 'Disconnected origin fabricated an HTTP response.');
    } else {
        $response = $result->response;
        verify($response !== null && $response->headerLine('X-Lab-Attempt') === (string) $attempts, 'Final peer attempt differs.');
        verify($response->headerLine('X-Lab-Method') === strtoupper($method), 'Replay method changed.');
        verify($response->headerLine('X-Lab-Digest') === hash('sha256', $hasBody ? $payload : ''), 'Replayed request body differed.');
        verify($response->headerLine('X-Lab-Same') === 'yes', 'Peer observed inconsistent replay identity.');
        verify($response->headerLine('X-Lab-Key') === (str_ends_with($contract, '-keyed') ? 'yes' : 'no'), 'Idempotency key policy differs.');
        if ($method === 'head') {
            verify($response->text() === '', 'HEAD retry returned body.');
        }
        $status = $success ? 200 : ($group === 'status' ? (int) $contract : 503);
        verify($result->statusCode === $status, 'Retry terminal status differs.');
    }
    if ($group === 'delay') {
        $expectedWaits = match ($contract) {
            'zero', 'past-date' => [],
            'capped', 'huge' => [2000000, 2000000],
            'invalid', 'missing' => [10000, 20000],
            'exhausted' => [10000],
            default => throw new InvalidArgumentException('Unknown delay contract.'),
        };
        verify($waits === $expectedWaits, 'Retry-After/backoff/cap decision differs.');
    } elseif ($group === 'transport') {
        verify($waits === ($success ? [10000, 20000] : []), 'Transport retry backoff differs.');
    }
    $ordinary = HttpClient::curl();
    $counts = $ordinary->send(HttpRequest::get($base . '/counts')->caBundle($ca))->response->json();
    verify(($counts[$path] ?? 0) === $attempts, 'Independent wire count differs.');
    verify($ordinary->send(HttpRequest::get($base . '/health')->caBundle($ca))->successful, 'Peer not reusable after retry boundary.');
    echo "Real HTTP retry $group/$contract: $attempts wire attempts, policy and recovery passed\n";
}
