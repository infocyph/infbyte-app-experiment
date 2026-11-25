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
