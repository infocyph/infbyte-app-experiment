<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Http\Cookie\CookieJar;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\HttpResponse;
use Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy;

function httpPolicyWorkload(): void
{
    $base = 'https://localhost:' . (int) getenv('LAB_HTTP_PORT');
    $ca = (string) getenv('LAB_HTTP_CA');
    $request = static fn(string $path): HttpRequest => HttpRequest::get($base . $path)->caBundle($ca)->timeout(5);
    $client = HttpClient::curl();
    verify(!$client->get($base . '/untrusted')->successful, 'Untrusted TLS accepted.');
    verify($client->send($request('/health'))->successful, 'Trusted TLS failed.');
    $retry = $client->withHttpRetry(HttpRetryPolicy::standard(attempts: 3, baseDelayMs: 10));
    $result = $retry->send($request('/retry'));
    verify($result->successful && $result->response instanceof HttpResponse && $result->response->json()['attempt'] === 3, 'Retry did not reconcile actual peer attempts.');
    $missing = $retry->send($request('/missing'));
    verify(!$missing->successful && $missing->statusCode === 404, 'Non-retryable status mismatch.');
    $auth = $client->withBearerToken('disposable-token')->withApiKeyHeader('X-Lab-Key', 'disposable-key');
    $direct = $auth->send($request('/inspect'));
    verify($direct->successful && $direct->response->json()['authorization'] && $direct->response->json()['api_key'], 'Initial credential application failed.');
    $redirect = $auth->send($request('/redirect')->followRedirects(true, 3));
    verify($redirect->successful && !$redirect->response->json()['authorization'] && !$redirect->response->json()['api_key'], 'Cross-origin redirect forwarded credentials.');
    $blocked = $client->send($request('/redirect')->allowHosts(['localhost'])->followRedirects(true, 3));
    verify(!$blocked->successful, 'Redirect bypassed host allow-list.');
    $private = $client->send($request('/private-denied')->blockPrivateNetworks());
    verify(!$private->successful, 'Private network destination accepted under explicit block policy.');
    $loop = $client->send($request('/loop')->followRedirects(true, 2));
    verify(!$loop->successful, 'Redirect loop exceeded configured bound.');
    $jar = new CookieJar(maxCookies: 8);
    $cookies = $client->withCookieJar($jar);
    verify($cookies->send($request('/cookie'))->successful && $jar->count() === 1, 'Secure cookie not stored.');
    verify($cookies->send($request('/private/check'))->response->json()['cookie'] === true, 'Cookie missing from authorized path.');
    verify($cookies->send($request('/private-other'))->response->json()['cookie'] === false, 'Cookie crossed path boundary.');
    $slow = $client->send($request('/slow')->timeout(1));
    verify(!$slow->successful && $client->send($request('/health'))->successful, 'Timeout or subsequent recovery failed.');
    $counts = $client->send($request('/counts'))->response->json();
    verify($counts['/retry'] === 3 && $counts['/missing'] === 1 && !isset($counts['/private-denied']) && !isset($counts['/untrusted']), 'Actual wire request counts violated security or retry policy.');
    echo "Real HTTPS trust, retry, redirect credentials, SSRF policy, cookie path, timeout and recovery passed\n";
}
