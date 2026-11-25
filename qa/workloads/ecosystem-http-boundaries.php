<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\HttpResponse;

function labHttpBoundaryRequest(string $path, string $method = 'get'): HttpRequest
{
    $base = 'https://localhost:' . (int) getenv('LAB_HTTP_PORT');

    return HttpRequest::$method($base . $path)->caBundle((string) getenv('LAB_HTTP_CA'))->timeout(5);
}

function httpRedirectMethodContracts(int $status, string $method): void
{
    $client = HttpClient::curl();
    $path = '/redirect-contract/' . $status . '/' . $method;
    $payload = "order\x00receipt\xff";
    $request = labHttpBoundaryRequest($path, $method)->followRedirects(true, 2)->withBearerToken('fixture-only');
    $bodyMethods = ['post', 'put', 'patch', 'delete'];
    if (in_array($method, $bodyMethods, true)) {
        $request = $request->raw($payload, 'application/octet-stream');
    }
    $result = $client->send($request);
    verify($result->successful && $result->response instanceof HttpResponse, 'Redirect chain failed.');
    $expected = match (true) {
        $status === 303 && $method !== 'head' => 'GET',
        in_array($status, [301, 302], true) && $method === 'post' => 'GET',
        default => strtoupper($method),
    };
    $body = in_array(strtolower($expected), $bodyMethods, true) ? $payload : '';
    $response = $result->response;
    verify($response->headerLine('X-Lab-Method') === $expected, 'Redirect method changed incorrectly.');
    verify($response->headerLine('X-Lab-Bytes') === (string) strlen($body)
        && $response->headerLine('X-Lab-Digest') === hash('sha256', $body), 'Redirect body changed or leaked.');
    verify($response->headerLine('X-Lab-Content-Type') === ($body === '' ? 'absent' : 'application/octet-stream'),
        'Redirect entity headers did not follow method.');
    verify($response->headerLine('X-Lab-Authorization') === 'present', 'Same-origin redirect lost authentication.');
    if ($method === 'head') {
        verify($response->text() === '', 'HEAD redirect returned a body.');
    }
    $counts = $client->send(labHttpBoundaryRequest('/counts'))->response->json();
    verify(($counts[$path] ?? 0) === 1 && ($counts['/redirect-target/' . $status . '/' . $method] ?? 0) === 1,
        'Redirect request multiplicity differed.');
    echo "Real $method $status redirect method, body, headers and wire counts passed\n";
}

function httpQueryRedirectContract(): void
{
    $client = HttpClient::curl();
    $response = $client->send(labHttpBoundaryRequest('/redirect-query')->followRedirects(true, 2));
    verify($response->successful && $response->response->json() === [
        'next' => 'https://example.test/continue', 'phase' => 'target',
    ], 'Query-only redirect did not preserve path and literal query URI.');
    $counts = $client->send(labHttpBoundaryRequest('/counts'))->response->json();
    verify(($counts['/redirect-query'] ?? 0) === 2, 'Query-only redirect sent an unexpected number of wire requests.');
    echo "Query-only URI reference containing a scheme-like query value passed\n";
}

function httpDowngradeRedirectContract(): void
{
    $client = HttpClient::curl();
    $request = labHttpBoundaryRequest('/redirect-downgrade')->followRedirects(true, 2)->withBearerToken('fixture-only');
    verify(!$client->send($request)->successful, 'HTTPS downgrade admitted by default.');
    $counts = $client->send(labHttpBoundaryRequest('/counts'))->response->json();
    verify(!isset($counts['/downgrade-target']), 'Blocked downgrade reached plaintext origin.');
    $allowed = $client->send($request->allowHttpsDowngradeRedirect());
    verify($allowed->successful && $allowed->response->json() === ['authorization' => false], 'Explicit downgrade lost credential isolation.');
    $counts = $client->send(labHttpBoundaryRequest('/counts'))->response->json();
    verify(($counts['/redirect-downgrade'] ?? 0) === 2 && ($counts['/downgrade-target'] ?? 0) === 1, 'Downgrade wire count mismatch.');
    echo "Default HTTPS downgrade denial and explicit credential-isolated opt-in passed\n";
}

function httpMultiRedirectContract(): void
{
    $pool = HttpClient::multi(2)->sendMany([
        'blocked' => labHttpBoundaryRequest('/redirect-query')->followRedirects(),
        'sibling' => labHttpBoundaryRequest('/health'),
    ]);
    verify($pool->get('blocked')?->successful === false && $pool->get('sibling')?->successful === true,
        'Unsupported multi-redirect admission damaged sibling.');
    $counts = HttpClient::curl()->send(labHttpBoundaryRequest('/counts'))->response->json();
    verify(!isset($counts['/redirect-query']) && ($counts['/health'] ?? 0) === 1, 'Unsupported redirect reached origin.');
    echo "Concurrent transport rejected unsupported redirect before wire I/O and preserved sibling\n";
}

function httpResponseLimitContracts(string $transport, string $contract): void
{
    $request = labHttpBoundaryRequest($contract === 'body' ? '/limit-body' : '/limit-header');
    // Fixture writes this exact HTTP/1.1 header block without server-generated headers.
    $header = "HTTP/1.1 200 OK\r\nContent-Length: 4\r\nContent-Type: text/plain\r\nX-Lab-Field: a\r\nX-Lab-Field: b\r\n\r\n";
    [$accepted, $denied] = match ($contract) {
        'body' => [$request->maxResponseBytes(4096), $request->maxResponseBytes(4095)],
        'header-bytes' => [$request->maxResponseHeaderBytes(strlen($header)), $request->maxResponseHeaderBytes(strlen($header) - 1)],
        'header-count' => [$request->maxResponseHeaderCount(4), $request->maxResponseHeaderCount(3)],
        default => throw new InvalidArgumentException('Unknown HTTP limit contract.'),
    };
    if ($transport === 'single') {
        $client = HttpClient::curl();
        $bad = $client->send($denied);
        $good = $client->send($accepted);
        verify($client->send(labHttpBoundaryRequest('/health'))->successful, 'Single client failed after limited response.');
    } else {
        verify($transport === 'multi', 'Unknown HTTP transport.');
        $client = HttpClient::multi(3);
        $results = $client->sendMany(['denied' => $denied, 'accepted' => $accepted, 'sibling' => labHttpBoundaryRequest('/health')]);
        $bad = $results->get('denied');
        $good = $results->get('accepted');
        verify($results->get('sibling')?->successful === true, 'Bounded response damaged concurrent sibling.');
    }
    verify($bad?->successful === false && str_contains((string) $bad->error, 'exceeded max allowed'), 'One-over limit did not report configured bound.');
    verify($good?->successful === true && $good->response instanceof HttpResponse, 'Exact response bound rejected.');
    if ($contract === 'body') {
        verify($good->response->text() === str_repeat('abcd', 1024), 'Bounded body lost or changed bytes.');
    } else {
        verify($good->response->text() === 'abcd' && $good->response->header('X-Lab-Field') === ['a', 'b'],
            'Exact header bound lost repeated field or body.');
    }
    $counts = HttpClient::curl()->send(labHttpBoundaryRequest('/counts'))->response->json();
    verify(($counts[$contract === 'body' ? '/limit-body' : '/limit-header'] ?? 0) === 2 && ($counts['/health'] ?? 0) === 1,
        'Response limit retry or sibling wire count mismatch.');
    echo "Real $transport $contract exact/one-over boundary and recovery passed\n";
}
