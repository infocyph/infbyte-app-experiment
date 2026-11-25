<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\HttpResponse;

function httpPoolWorkload(): void
{
    $base = 'https://localhost:' . (int) getenv('LAB_HTTP_PORT');
    $ca = (string) getenv('LAB_HTTP_CA');
    $request = static fn(string $path): HttpRequest => HttpRequest::get($base . $path)->caBundle($ca)->timeout(5);
    $requests = [];
    for ($i = 0; $i < 64; ++$i) {
        $requests['request-' . $i] = $request('/pool/' . $i);
    }
    $pool = HttpClient::multi(8)->sendMany($requests);
    verify($pool->successfulCount() === 64 && $pool->failedCount() === 0, 'Concurrent request completion mismatch.');
    foreach ($requests as $key => $_) {
        $result = $pool->get($key);
        verify($result?->response instanceof HttpResponse && $result->response->json() === ['path' => '/pool/' . substr($key, 8), 'attempt' => 1], 'Response correlation mismatch.');
    }
    $stop = HttpClient::multi(1)->stopSchedulingOnFailure()->sendMany(['failure' => $request('/missing'), 'never' => $request('/not-scheduled')]);
    verify($stop->metadata['stopped_scheduling'] === true && $stop->get('failure')?->statusCode === 404 && $stop->get('never')?->successful !== true, 'Failure did not stop scheduling.');
    $cancel = HttpClient::multi(4, cancellation: CancellationSignal::fromCallable(static fn(): bool => true))->sendMany($requests);
    verify($cancel->metadata['cancelled'] === true && $cancel->successfulCount() === 0 && $cancel->failedCount() === 64, 'Cancelled pool lost result correlation.');
    $deadline = HttpClient::multi(2)->withOperationDeadline(OperationDeadline::after(0.15))->sendMany(['a' => $request('/slow'), 'b' => $request('/slow'), 'c' => $request('/deadline-never')]);
    verify($deadline->metadata['deadline_exceeded'] === true && $deadline->successfulCount() === 0 && $deadline->failedCount() === 3, 'Operation deadline did not terminate pool.');
    $counts = HttpClient::curl()->send($request('/counts'))->response->json();
    verify(!isset($counts['/not-scheduled']) && !isset($counts['/deadline-never']), 'Unscheduled request reached the peer.');
    for ($i = 0; $i < 64; ++$i) {
        verify($counts['/pool/' . $i] === 1, 'Cancelled or completed request replayed.');
    }
    echo "64 correlated real concurrent requests, failure stop, cancellation and operation deadline passed\n";
}

function httpTransferWorkload(): void
{
    $root = evidenceDirectory() . '/http-transfer';
    verify(mkdir($root, 0700), 'Transfer fixture directory unavailable.');
    $base = 'https://localhost:' . (int) getenv('LAB_HTTP_PORT');
    $ca = (string) getenv('LAB_HTTP_CA');
    $client = HttpClient::curl();
    $payload = str_repeat(implode('', array_map(chr(...), range(0, 255))), 4096);
    $digest = hash('sha256', $payload);
    $source = $root . '/source.bin';
    verify(file_put_contents($source, $payload) === strlen($payload), 'Source fixture write failed.');
    $upload = HttpRequest::put($base . '/upload')->caBundle($ca)->uploadFromFile($source)->maxUploadBytes(strlen($payload))->timeout(5);
    $result = $client->send($upload);
    verify($result->successful && $result->response->json() === ['bytes' => strlen($payload), 'digest' => $digest], 'File upload digest mismatch.');
    $stream = fopen($source, 'rb');
    verify(is_resource($stream), 'Source stream unavailable.');
    try {
        $multipart = HttpClient::multipart()->addField('tenant', 'alpha')->addStream('document', $stream, 'fixture.bin', knownSize: strlen($payload));
        $response = $client->send(HttpRequest::post($base . '/multipart')->caBundle($ca)->multipart($multipart)->maxUploadBytes(strlen($payload) + 1024)->timeout(5));
        verify($response->successful && $response->response->json()['document'] === ['bytes' => strlen($payload), 'digest' => $digest, 'filename' => 'fixture.bin'], 'Multipart document mismatch.');
        verify($response->response->json()['tenant']['digest'] === hash('sha256', 'alpha'), 'Multipart field mismatch.');
    } finally {
        fclose($stream);
    }
    $request = HttpRequest::get($base . '/download')->caBundle($ca)->timeout(5);
    foreach (['buffered', 'streamed'] as $mode) {
        $path = $root . '/' . $mode . '.bin';
        $download = $mode === 'buffered' ? $request->downloadTo($path) : $request->streamDownloadTo($path);
        verify($client->send($download->maxDownloadBytes(strlen($payload)))->successful && hash_file('sha256', $path) === $digest, 'Download bytes mismatch.');
    }
    $denied = $root . '/denied.bin';
    $failure = $client->send($request->streamDownloadTo($denied)->maxDownloadBytes(4096));
    verify(!$failure->successful, 'Download exceeded configured byte limit.');
    echo "Real 1 MiB upload, multipart stream, two download modes and byte bound passed\n";
}

function httpRetrySafetyWorkload(): void
{
    $base = 'https://localhost:' . (int) getenv('LAB_HTTP_PORT');
    $ca = (string) getenv('LAB_HTTP_CA');
    $client = HttpClient::curl();
    $policy = \Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy::standard(attempts: 3, baseDelayMs: 10);
    $retry = $client->withHttpRetry($policy);
    $payload = "fixture\0receipt";
    $post = static fn(string $path): HttpRequest => HttpRequest::post($base . $path)->caBundle($ca)->raw($payload)->timeout(5);
    verify($retry->send($post('/mutation/no-key'))->statusCode === 503, 'Unsafe POST was automatically replayed.');
    $safe = $retry->send($post('/mutation/key')->header('Idempotency-Key', 'fixture-operation'));
    verify($safe->successful && $safe->response->json()['attempt'] === 3 && $safe->response->json()['same_body'] && $safe->response->json()['same_key'], 'Keyed POST retry lost payload or identity.');
    $generated = $client->withIdempotency()->withHttpRetry($policy)->send($post('/mutation/generated'));
    verify($generated->successful && $generated->response->json()['attempt'] === 3 && $generated->response->json()['same_key'] && $generated->response->json()['key_present'], 'Generated idempotency identity changed on retry.');
    $opted = $retry->send($post('/mutation/opt-in')->allowUnsafeMethodRetry());
    verify($opted->successful && $opted->response->json()['attempt'] === 3 && $opted->response->json()['same_body'], 'Explicitly enabled POST retry failed.');
    $request = static fn(string $path): HttpRequest => HttpRequest::get($base . $path)->caBundle($ca)->timeout(5);
    $deadline = $retry->send($request('/retry-deadline')->withOperationDeadline(OperationDeadline::after(1.0)));
    verify(!$deadline->successful && ($deadline->metadata['deadline_exceeded'] ?? false) === true && ($deadline->metadata['attempts'] ?? null) === 1, 'Retry delay exceeded operation deadline or replayed.');
    $cancelPath = (string) getenv('LAB_HTTP_ROOT') . '/retry-cancel';
    $signal = CancellationSignal::fromCallable(static fn(): bool => is_file($cancelPath));
    $cancelled = $client->withHttpRetry($policy, cancellation: $signal)->send($request('/retry-cancel'));
    verify(!$cancelled->successful && ($cancelled->metadata['cancelled'] ?? false) === true && ($cancelled->metadata['attempts'] ?? null) === 1, 'Cancellation after first peer response did not stop retry.');
    $counts = $client->send($request('/counts'))->response->json();
    foreach (['/mutation/no-key' => 1, '/mutation/key' => 3, '/mutation/generated' => 3, '/mutation/opt-in' => 3, '/retry-deadline' => 1, '/retry-cancel' => 1] as $path => $expected) {
        verify(($counts[$path] ?? 0) === $expected, 'Unexpected wire attempt count: ' . $path);
    }
    verify($client->send($request('/health'))->successful, 'Client did not recover after cancelled retry.');
    echo "Real POST retry safety, stable idempotency identity, operation deadline and cancellation passed\n";
}

function httpProxyWorkload(): void
{
    $port = (int) getenv('LAB_HTTP_PORT');
    $proxy = 'http://127.0.0.1:' . (int) getenv('LAB_HTTP_PROXY');
    $request = HttpRequest::get('https://localhost:' . $port . '/proxy-inspect')->caBundle((string) getenv('LAB_HTTP_CA'))->proxy($proxy)->timeout(5);
    $client = HttpClient::curl();
    $root = (string) getenv('LAB_HTTP_ROOT');
    $snapshot = static fn(): array => json_decode((string) file_get_contents($root . '/proxy-counts.json'), true, flags: JSON_THROW_ON_ERROR);
    $before = $snapshot();
    verify(!$client->send($request->proxyAuth('fixture', 'wrong'))->successful, 'Proxy accepted invalid credentials.');
    $good = $request->proxyAuth('fixture', 'fixture-only-password')->withBearerToken('origin-only-token');
    $result = $client->send($good);
    verify($result->successful && $result->response->json() === ['authorization' => true, 'proxy_authorization' => false], 'Origin credential or proxy credential boundary failed.');
    $after = $snapshot();
    verify($after['denied'] > $before['denied'] && $after['accepted'] === $before['accepted'] + 1, 'Expected authenticated CONNECT was not observed.');
    verify(!$client->send($good->blockPrivateNetworks())->successful, 'Strict private-address policy allowed remote proxy.');
    verify(!$client->send($good->allowHosts(['foreign.example.test']))->successful, 'Proxy bypassed explicit host allowlist.');
    verify($snapshot() === $after, 'Blocked request reached proxy.');
    $untrusted = HttpRequest::get('https://localhost:' . $port . '/proxy-untrusted')->proxy($proxy)->proxyAuth('fixture', 'fixture-only-password')->timeout(5);
    verify(!$client->send($untrusted)->successful, 'Proxy bypassed origin TLS verification.');
    $counts = $client->send(HttpRequest::get('https://localhost:' . $port . '/counts')->caBundle((string) getenv('LAB_HTTP_CA')))->response->json();
    verify(($counts['/proxy-inspect'] ?? 0) === 1 && !isset($counts['/proxy-untrusted']), 'Origin request count violated proxy security boundary.');
    echo "Authenticated CONNECT tunnel, origin TLS, credential isolation and preflight host policies passed\n";
}
