<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\ClientRedirectRetryPolicy;
use Infocyph\Runwire\Http\Client\Http1PolicyClient;
use Infocyph\Runwire\Http\Client\Http1OutboundClient;
use Infocyph\Runwire\Http\Client\Http2OutboundClient;
use Infocyph\Runwire\Http\Client\HttpNegotiatingOutboundClient;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\TlsClientOptions;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

function runwireOutboundContracts(string $protocol, string $contract): void
{
    $version = match ($protocol) {
        'h1' => ProtocolVersion::HTTP_1_1,
        'h2' => ProtocolVersion::HTTP_2,
        default => throw new InvalidArgumentException('Unknown outbound protocol.'),
    };
    $client = $protocol === 'h1' ? Http1OutboundClient::class : Http2OutboundClient::class;
    $alpn = $protocol === 'h1' ? 'http/1.1' : 'h2';
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $base = 'https://127.0.0.1:' . (int) getenv('LAB_RUNWIRE_OUTBOUND_PORT');
    $options = new DialOptions(connectTimeoutSeconds: 3.0, tls: new TlsClientOptions(
        '127.0.0.1', caFile: (string) getenv('LAB_RUNWIRE_OUTBOUND_CA'), alpnProtocols: [$alpn], handshakeTimeoutSeconds: 3.0,
    ));
    $policy = new ClientProtocolPolicy($version, [$version]);
    $execute = static function (string $case) use ($base, $client, $loop, $dialer, $options, $policy, $alpn): array {
        $source = new CancellationSource();
        $method = $case === 'head' ? 'HEAD' : 'GET';
        $deadline = $case === 'deadline' ? RequestDeadline::afterSeconds(2.5, hrtime(true)) : null;
        $request = new ClientRequest($method, $base . '/' . $case, Headers::fromArray(['x-lab-client' => 'native']),
            protocol: $policy, cancellation: $source->token(), deadline: $deadline);
        $statuses = [];
        $body = '';
        $failure = null;
        $completed = 0;
        $cancelTimer = null;
        $watchdog = $loop->delay(10.0, static fn() => $loop->stop());
        try {
            $client::connect($loop, $dialer, $request,
                static function (int $status, Headers $headers) use (&$statuses, &$cancelTimer, $case, $source, $loop, $alpn): void {
                    $statuses[] = $status;
                    verify($headers->all('x-lab-alpn') === [$alpn] && $headers->all('x-lab-field') === ['first', 'second'],
                        'Origin protocol or repeated response fields changed.');
                    if ($case === 'cancel') {
                        $cancelTimer = $loop->delay(0.05, static fn() => $source->cancel(CancellationReason::HOST_CANCELLED));
                    }
                },
                static function (string $part) use (&$body, &$completed): void {
                    verify($completed === 0, 'Body delivered after terminal completion.');
                    $body .= $part;
                },
                static function (?Connection $connection, ?Throwable $error) use (&$completed, &$failure, $loop): void {
                    ++$completed;
                    $failure = $error;
                    $connection?->abort();
                    $loop->stop();
                }, $options);
            $loop->run();
            verify($completed === 1, 'Outbound completion missing or duplicated.');
        } finally {
            $loop->cancel($watchdog);
            if ($cancelTimer !== null) {
                $loop->cancel($cancelTimer);
            }
        }
        verify($dialer->pendingCount() === 0 && $numeric->pendingCount() === 0, 'Dial ownership leaked.');
        return [$statuses, $body, $failure];
    };

    try {
        [$statuses, $body, $failure] = $execute($contract);
        $expected = match ($contract) {
            'get' => [200, 'native-response'],
            'head' => [200, ''],
            'binary' => [200, str_repeat("\x00\xffABC", 4096)],
            'no-content' => [204, ''],
            'not-modified' => [304, ''],
            'not-found' => [404, 'native-response'],
            'unavailable' => [503, 'native-response'],
            'short', 'long', 'disconnect', 'deadline', 'cancel' => null,
            default => throw new InvalidArgumentException('Unknown outbound response contract.'),
        };
        if ($expected !== null) {
            verify($failure === null && $statuses === [$expected[0]] && $body === $expected[1],
                'Valid response failed or changed its status/body.');
        } else {
            verify($failure instanceof Throwable && $statuses === [200], 'Invalid/incomplete response reported success or lost its admitted head.');
            if ($contract === 'cancel') {
                verify($failure instanceof CancelledException && $body === '', 'Cancellation delivered body or wrong terminal error.');
            }
            if ($contract === 'deadline') {
                verify(str_contains(strtolower($failure->getMessage()), 'deadline') && $body === '', 'Response deadline did not terminate delayed body.');
            }
        }
        [$statuses, $body, $failure] = $execute('recovery');
        verify($failure === null && $statuses === [200] && $body === 'native-response', 'Replacement exchange failed after terminal outcome.');
    } finally {
        $dialer->close();
        $numeric->close();
        $executor->close();
    }
    $diagnostics = $loop->diagnostics();
    verify($diagnostics->readWatchers === 0 && $diagnostics->writeWatchers === 0 && $diagnostics->timersActive === 0,
        'Outbound exchange leaked loop resources.');
    echo "Native $protocol $contract and healthy replacement exchange passed\n";
}

function runwireH1PolicyContracts(string $contract): void
{
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $tls = $contract === 'downgrade';
    $host = '127.0.0.1';
    $base = ($tls ? 'https' : 'http') . '://' . $host . ':' . (int) getenv($tls ? 'LAB_POLICY_TLS_PORT' : 'LAB_POLICY_PORT');
    $policy = new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true);
    $method = str_ends_with($contract, '-head') ? 'HEAD' : (in_array($contract, ['unsafe-post', 'retry-post'], true) ? 'POST' : 'GET');
    $path = match ($contract) {
        'query' => '/query?seed=1',
        'relative' => '/relative/base/start',
        default => '/' . $contract,
    };
    $request = new ClientRequest($method, $base . $path, Headers::fromArray([
        'authorization' => 'Bearer fixture', 'cookie' => 'fixture=yes', 'x-tenant' => 'private-fixture', 'accept' => 'text/plain',
    ]), protocol: $policy);
    $statuses = [];
    $body = '';
    $failure = null;
    $complete = 0;
    $target = null;
    $client = new Http1PolicyClient(
        $loop, $dialer, $request,
        new ClientRedirectRetryPolicy(maxRedirects: 1, maxRetries: 1, allowCrossOrigin: in_array($contract, ['cross-allow', 'downgrade'], true)),
        static function (int $status, Headers $headers) use (&$statuses, &$target): void {
            $statuses[] = $status;
            $target = $headers->first('x-lab-target');
        },
        static function (string $part) use (&$body): void { $body .= $part; },
        static function (?Connection $connection, ?Throwable $error) use (&$complete, &$failure, $loop): void {
            ++$complete;
            $failure = $error;
            $connection?->abort();
            $loop->stop();
        },
        new DialOptions(connectTimeoutSeconds: 3.0, tls: $tls ? new TlsClientOptions($host, caFile: (string) getenv('LAB_POLICY_CA'),
            alpnProtocols: ['http/1.1']) : null),
    );
    $watchdog = $loop->delay(10.0, static fn() => $loop->stop());
    try {
        $client->start();
        $loop->run();
        verify($complete === 1 && $dialer->pendingCount() === 0 && $numeric->pendingCount() === 0, 'Policy terminal or dial ownership failed.');
        $expected = match ($contract) {
            'cross-deny' => 'Cross-origin',
            'unsafe-post' => 'Unsafe HTTP redirect',
            'loop' => 'budget',
            'ambiguous' => 'Ambiguous',
            'downgrade' => 'plaintext',
            'retry-exhausted', 'retry-post', 'retry-after-head' => '',
            default => null,
        };
        if ($expected !== null) {
            verify($failure instanceof Throwable && ($expected === '' || str_contains($failure->getMessage(), $expected)), 'Invalid policy outcome reported success or wrong failure.');
            verify($statuses === ($contract === 'retry-after-head' ? [200] : []) && $body === ($contract === 'retry-after-head' ? 'part' : ''), 'Intermediate or failed response leaked callbacks.');
        } else {
            verify($failure === null && $statuses === [200] && $body === ($method === 'HEAD' ? '' : 'settled'),
                'Policy lost final response or exposed intermediate body.');
            verify($target === ($contract === 'cross-allow' ? 'other' : 'primary'), 'Final response origin differed.');
        }
    } finally {
        $loop->cancel($watchdog);
        $dialer->close();
        $numeric->close();
        $executor->close();
    }
    $diagnostics = $loop->diagnostics();
    verify($diagnostics->readWatchers === 0 && $diagnostics->writeWatchers === 0 && $diagnostics->timersActive === 0, 'Policy run leaked loop resources.');
    echo "Native H1 policy $contract passed\n";
}

function runwireNegotiationContracts(string $protocol, string $contract): void
{
    $actual = $protocol === 'h1' ? ProtocolVersion::HTTP_1_1 : ProtocolVersion::HTTP_2;
    $other = $protocol === 'h1' ? ProtocolVersion::HTTP_2 : ProtocolVersion::HTTP_1_1;
    $alpn = static fn(ProtocolVersion $version): string => $version === ProtocolVersion::HTTP_2 ? 'h2' : 'http/1.1';
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $base = '127.0.0.1:' . (int) getenv('LAB_RUNWIRE_OUTBOUND_PORT');
    $ca = (string) getenv('LAB_RUNWIRE_OUTBOUND_CA');
    $execute = static function (string $case) use ($actual, $other, $alpn, $loop, $dialer, $base, $ca): array {
        $preferred = match ($case) {
            'reverse-fallback', 'reverse-strict', 'reverse-only' => $other,
            'h3-preferred' => ProtocolVersion::HTTP_3,
            'plaintext-h2' => ProtocolVersion::HTTP_2,
            default => $actual,
        };
        $allowed = match ($case) {
            'preferred-fallback', 'reverse-fallback', 'reverse-strict' => [$preferred, $preferred === $actual ? $other : $actual],
            'h3-preferred' => [ProtocolVersion::HTTP_3, $actual],
            default => [$preferred],
        };
        $fallback = in_array($case, ['preferred-fallback', 'reverse-fallback', 'h3-preferred', 'plaintext-h2'], true);
        $policy = new ClientProtocolPolicy($preferred, $allowed, allowFallback: $fallback, allowPlaintext: $case === 'plaintext-h2');
        $options = $case === 'plaintext-h2' || $case === 'h3-preferred'
            ? new DialOptions(connectTimeoutSeconds: 3.0)
            : new DialOptions(connectTimeoutSeconds: 3.0, tls: new TlsClientOptions(
                $case === 'wrong-peer' ? 'other.invalid' : '127.0.0.1',
                caFile: $case === 'wrong-ca' ? (string) getenv('LAB_RUNWIRE_WRONG_CA') : $ca,
                alpnProtocols: $case === 'wrong-offer' ? [$alpn($other)] : array_map($alpn, $fallback ? $allowed : [$preferred]),
                handshakeTimeoutSeconds: 3.0,
            ));
        $path = $case === 'recovery' ? 'recovery' : 'get';
        $request = new ClientRequest('GET', ($case === 'plaintext-h2' ? 'http://' : 'https://') . $base . '/' . $path,
            Headers::fromArray(['x-lab-client' => 'native']), protocol: $policy);
        $statuses = [];
        $body = '';
        $completed = 0;
        $failure = null;
        $watchdog = $loop->delay(10.0, static fn() => $loop->stop());
        try {
            try {
                HttpNegotiatingOutboundClient::connect($loop, $dialer, $request,
                    static function (int $status, Headers $headers) use (&$statuses, $actual, $alpn): void {
                        $statuses[] = $status;
                        verify($headers->all('x-lab-alpn') === [$alpn($actual)], 'Negotiated origin differs.');
                    },
                    static function (string $part) use (&$body): void { $body .= $part; },
                    static function (?Connection $connection, ?Throwable $error) use (&$completed, &$failure, $loop): void {
                        ++$completed;
                        $failure = $error;
                        $connection?->abort();
                        $loop->stop();
                    }, $options);
            } catch (InvalidArgumentException|\Infocyph\Runwire\Exception\RuntimeUnavailableException $error) {
                verify(in_array($case, ['wrong-peer', 'wrong-offer', 'h3-preferred', 'plaintext-h2'], true), 'Unexpected synchronous rejection.');
                return [$statuses, $body, $error, 0];
            }
            $loop->run();
            verify($completed === 1, 'Negotiated exchange did not settle exactly once.');
            return [$statuses, $body, $failure, $completed];
        } finally {
            $loop->cancel($watchdog);
        }
    };
    try {
        [$statuses, $body, $failure, $completed] = $execute($contract);
        if (in_array($contract, ['strict', 'preferred-fallback', 'reverse-fallback'], true)) {
            verify($failure === null && $completed === 1 && $statuses === [200] && $body === 'native-response', 'Permitted protocol negotiation failed.');
        } else {
            verify($failure instanceof Throwable && $statuses === [] && $body === '', 'Forbidden negotiation admitted a response.');
            if (in_array($contract, ['wrong-peer', 'wrong-offer', 'h3-preferred', 'plaintext-h2'], true)) {
                verify($completed === 0 && $executor->diagnostics()['submitted'] === 0, 'Preflight rejection performed DNS or transport work.');
            }
        }
        verify($dialer->pendingCount() === 0 && $numeric->pendingCount() === 0, 'Negotiation leaked a pending dial.');
        [$statuses, $body, $failure, $completed] = $execute('recovery');
        verify($failure === null && $completed === 1 && $statuses === [200] && $body === 'native-response', 'Healthy recovery after negotiation failed.');
    } finally {
        $dialer->close();
        $numeric->close();
        $executor->close();
    }
    $diagnostics = $loop->diagnostics();
    verify($diagnostics->readWatchers === 0 && $diagnostics->writeWatchers === 0 && $diagnostics->timersActive === 0, 'Negotiation leaked loop resources.');
    echo "Native TLS negotiation $protocol $contract passed\n";
}
