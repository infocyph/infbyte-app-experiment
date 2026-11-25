<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http1OutboundClient;
use Infocyph\Runwire\Http\Client\Http2OutboundClient;
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
