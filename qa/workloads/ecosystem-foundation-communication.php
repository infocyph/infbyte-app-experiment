<?php

declare(strict_types=1);

use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpClientConfig;
use Infocyph\TalkingBytes\Webhook\WebhookMessage;
use Infocyph\TalkingBytes\Webhook\WebhookSender;

function foundationCommunicationContracts(string $contract): void
{
    $base = 'https://localhost:' . (int) getenv('LAB_HTTP_PORT');
    $ca = (string) getenv('LAB_HTTP_CA');
    $plain = ['caBundle' => $ca, 'timeoutSeconds' => 5, 'connectTimeoutSeconds' => 2];
    $auth = match ($contract) {
        'auth-header' => ['driver' => 'api_key_header', 'header' => 'X-Lab-Key', 'value' => 'fixture-key'],
        'auth-query' => ['driver' => 'api_key_query', 'query_key' => 'fixture_key', 'value' => 'fixture-key'],
        'auth-basic' => ['driver' => 'basic', 'username' => 'fixture', 'password' => 'fixture-password'],
        'auth-bearer' => ['driver' => 'bearer', 'token' => 'fixture-token'],
        default => ['driver' => 'none'],
    };
    $primary = $plain + [
        'auth' => $auth,
        'defaultHeaders' => ['X-Lab-Profile' => 'alpha'],
        'cookies' => ['enabled' => $contract === 'cookies'],
        'retry' => ['enabled' => in_array($contract, ['retry', 'idempotency', 'double-retry'], true), 'attempts' => 3, 'base_delay_ms' => 10],
        'idempotency' => ['enabled' => $contract === 'idempotency'],
        'rate_limit' => ['enabled' => $contract === 'rate-limit', 'max_requests' => 2, 'per_seconds' => 60],
        'circuit_breaker' => ['enabled' => $contract === 'circuit', 'failure_threshold' => 2, 'cool_down_seconds' => 1],
    ];
    $config = [
        'http' => ['default_client' => 'lab-primary', 'clients' => [
            'lab-primary' => $primary,
            'lab-secondary' => $plain + ['defaultHeaders' => ['X-Lab-Profile' => 'beta']],
            'lab-limited' => $plain + ['maxResponseBytes' => 4095],
            'lab-exact' => $plain + ['maxResponseBytes' => 4096],
            'lab-invalid' => $plain + ['auth' => ['driver' => 'invalid']],
            'lab-no-trust' => ['timeoutSeconds' => 2],
        ]],
        'webhooks' => [
            'default_inbound' => 'lab-inbound',
            'inbound' => ['lab-inbound' => ['secret' => 'fixture-inbound-only', 'replay' => ['enabled' => false]]],
            'default_outbound' => 'lab-outbound',
            'outbound' => [
                'lab-outbound' => ['http_client' => 'lab-primary', 'signing_secret' => 'fixture-signing-only',
                    'max_payload_bytes' => 64, 'retry' => ['enabled' => in_array($contract, ['webhook-retry', 'double-retry'], true), 'attempts' => 3, 'base_delay_ms' => 10]],
                'lab-invalid' => ['http_client' => 'missing-profile'],
            ],
        ],
    ];
    if (in_array($contract, ['production-peer', 'production-host'], true)) {
        $config['http']['clients']['lab-primary'][$contract === 'production-peer' ? 'verifyPeer' : 'verifyHost'] = false;
        $profiles = new CommunicationProfiles(new ConfigRepository(['app' => ['env' => 'production'], 'communication' => $config]));
        expectFailure(static fn() => $profiles->http(), LogicException::class, 'Production accepted disabled TLS verification.');
        verify($profiles->http('lab-secondary')->get($base . '/health')->successful, 'Secure named profile failed after rejection.');
        return;
    }
    $app = foundationProviderApp(['communication' => $config], ['communication']);
    $counts = static fn(): array => HttpClient::curl()->send(\Infocyph\TalkingBytes\Http\HttpRequest::get($base . '/counts')->caBundle($ca))->response->json();
    $first = $app->execution()->run(static function () use ($app, $contract, $base, $counts): HttpClient {
        $profiles = $app->make(CommunicationProfiles::class);
        verify($profiles === $app->make('foundation.communication'), 'Provider alias mismatch.');
        $client = $app->make(HttpClient::class);
        verify($client === $app->make(HttpClient::class), 'Scoped client identity changed.');
        $inspect = static fn(HttpClient $http): array => $http->get($base . '/foundation-profile')->response->json();
        switch ($contract) {
            case 'auth-header':
            case 'auth-query':
            case 'auth-basic':
            case 'auth-bearer':
                $actual = $inspect($client);
                $expected = substr($contract, 5);
                verify($actual['auth'] === $expected && $actual['profile'] === 'alpha', 'Configured authentication differed.');
                verify($inspect($profiles->http('lab-secondary'))['auth'] === 'none', 'Authentication crossed named profile.');
                break;
            case 'profile':
                $options = $app->make(HttpClientConfig::class);
                verify($options->timeoutSeconds === 5 && $options->connectTimeoutSeconds === 2 && $options->verifyPeer && $options->verifyHost, 'Default options differ.');
                verify($inspect($client)['profile'] === 'alpha' && $inspect($profiles->http('lab-secondary'))['profile'] === 'beta', 'Named profile selection differed.');
                break;
            case 'invalid-profile':
                expectFailure(static fn() => $profiles->http('missing-profile'), InvalidArgumentException::class, 'Missing HTTP profile accepted.');
                expectFailure(static fn() => $profiles->http('lab-invalid'), InvalidArgumentException::class, 'Invalid auth driver accepted.');
                expectFailure(static fn() => $profiles->webhookSender('lab-invalid'), InvalidArgumentException::class, 'Missing webhook HTTP profile accepted.');
                verify(!isset($counts()['/foundation-profile']) && $inspect($client)['profile'] === 'alpha', 'Invalid profile sent data or damaged valid profile.');
                break;
            case 'tls':
                verify(!$profiles->http('lab-no-trust')->get($base . '/foundation-untrusted')->successful, 'Untrusted profile succeeded.');
                verify(!isset($counts()['/foundation-untrusted']) && $client->get($base . '/health')->successful, 'Rejected TLS reached origin or damaged trusted client.');
                break;
            case 'body-limit':
                verify(!$profiles->http('lab-limited')->get($base . '/limit-body')->successful, 'Configured byte limit was not applied.');
                verify($profiles->http('lab-exact')->get($base . '/limit-body')->response->text() === str_repeat('abcd', 1024), 'Exact limit changed payload.');
                break;
            case 'cookies':
                verify($client->get($base . '/cookie')->successful && $client->get($base . '/private/check')->response->json()['cookie'], 'Configured cookie jar did not retain scoped cookie.');
                verify(!$profiles->http('lab-secondary')->get($base . '/private/check')->response->json()['cookie'], 'Cookie leaked to another profile.');
                break;
            case 'retry':
                verify($client->get($base . '/retry')->response->json()['attempt'] === 3 && ($counts()['/retry'] ?? 0) === 3, 'Configured retry count differed.');
                break;
            case 'idempotency':
                $result = $client->postRaw($base . '/mutation/foundation', 'fixture-receipt');
                verify($result->successful && $result->response->json()['attempt'] === 3 && $result->response->json()['same_key'] && $result->response->json()['key_present'] && $result->response->json()['same_body'], 'Configured idempotency or retry changed request.');
                break;
            case 'rate-limit':
                verify($client->get($base . '/foundation-rate')->successful && $client->get($base . '/foundation-rate')->successful, 'Configured initial rate budget failed.');
                verify(!$client->get($base . '/foundation-rate')->successful && ($counts()['/foundation-rate'] ?? 0) === 2, 'Rate rejection reached peer.');
                break;
            case 'circuit':
                verify(!$client->get($base . '/foundation-fail')->successful && !$client->get($base . '/foundation-fail')->successful, 'Circuit failure seed succeeded.');
                verify(!$client->get($base . '/foundation-fail')->successful && ($counts()['/foundation-fail'] ?? 0) === 2, 'Open circuit reached peer.');
                usleep(1100000);
                verify($client->get($base . '/health')->successful && $client->get($base . '/health')->successful, 'Half-open success did not restore circuit.');
                break;
            case 'webhook':
            case 'webhook-retry':
            case 'webhook-bound':
            case 'double-retry':
                if ($contract === 'double-retry') {
                    expectFailure(static fn() => $profiles->webhookSender(), InvalidArgumentException::class, 'Two retry layers accepted.');
                    verify(!isset($counts()['/foundation-webhook']), 'Invalid sender reached peer.');
                    break;
                }
                $sender = $app->make(WebhookSender::class);
                verify($sender === $app->make(WebhookSender::class), 'Scoped webhook sender identity changed.');
                $path = $contract === 'webhook-retry' ? '/foundation-webhook-retry' : '/foundation-webhook';
                $message = WebhookMessage::event('orders.created')->deliveryId('foundation-delivery')->url($base . $path);
                if ($contract === 'webhook-bound') {
                    expectFailure(static fn() => $sender->send($message->rawPayload(json_encode(str_repeat('x', 63), JSON_THROW_ON_ERROR))), InvalidArgumentException::class, 'Oversized payload sent.');
                    verify(!isset($counts()[$path]), 'Oversized payload reached peer.');
                    $body = json_encode(str_repeat('x', 62), JSON_THROW_ON_ERROR);
                } else {
                    $body = '{"id":42,"tenant":"alpha"}';
                }
                $sent = $sender->send($message->rawPayload($body));
                $attempts = $contract === 'webhook-retry' ? 3 : 1;
                verify($sent->result->successful && $sent->delivery?->attempts === $attempts, 'Configured webhook delivery failed.');
                verify($sent->result->response->json() === ['attempt' => $attempts, 'valid' => true, 'same_body' => true, 'profile' => 'alpha'], 'Independent webhook signature, identity or payload verification failed.');
                verify(($counts()[$path] ?? 0) === $attempts, 'Unexpected webhook wire attempts.');
                break;
            default:
                throw new InvalidArgumentException('Unknown communication contract.');
        }

        return $client;
    });
    $app->execution()->run(static function () use ($app, $first, $contract, $base, $counts): void {
        $replacement = $app->make(HttpClient::class);
        verify($replacement !== $first, 'Client leaked across execution scopes.');
        if ($contract === 'cookies') {
            verify(!$replacement->get($base . '/private/check')->response->json()['cookie'], 'Cookie leaked across requests.');
        } elseif ($contract === 'rate-limit') {
            verify($replacement->get($base . '/foundation-rate')->successful && ($counts()['/foundation-rate'] ?? 0) === 3, 'Replacement scope inherited exhausted rate limiter.');
        }
    });
    echo "Foundation communication $contract passed\n";
}
