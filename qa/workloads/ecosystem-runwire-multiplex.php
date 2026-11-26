<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http2MultiplexConnection;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\TlsClientOptions;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RequestDeadline;

function runwireMultiplexContracts(string $contract, int $count): void
{
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $port = (int) getenv('LAB_RUNWIRE_OUTBOUND_PORT');
    $origin = 'https://127.0.0.1:' . $port;
    $policy = new ClientProtocolPolicy(ProtocolVersion::HTTP_2, [ProtocolVersion::HTTP_2]);
    $source = new CancellationSource();
    $transport = null;
    $connection = null;
    $failure = null;
    $statuses = [];
    $bodies = [];
    $settled = [];
    $ids = [];
    $ready = 0;
    $recovered = false;
    $cancelTimer = null;
    $submit = static function (Http2MultiplexConnection $client, int $id, callable $complete) use (
        $origin, $policy, $contract, $source, $loop, &$statuses, &$bodies, &$cancelTimer, &$ids,
    ): void {
        $method = $contract === 'head' && $id % 2 === 1 ? 'HEAD' : 'GET';
        $request = new ClientRequest($method, $origin . '/stream/' . $id, protocol: $policy,
            cancellation: $contract === 'cancel' && $id === 1 ? $source->token() : null,
            deadline: $contract === 'deadline' && $id === 1 ? RequestDeadline::afterSeconds(2.0, hrtime(true)) : null);
        $ids[$id] = $client->request($request,
            static function (int $status, Headers $headers) use ($id, $contract, $source, $loop, &$statuses, &$cancelTimer): void {
                $statuses[$id][] = [$status, $headers->first('x-stream-id')];
                if ($contract === 'cancel' && $id === 1) {
                    $cancelTimer = $loop->delay(0.05, static fn() => $source->cancel(CancellationReason::HOST_CANCELLED));
                }
            },
            static function (string $part) use ($id, &$bodies): void { $bodies[$id] = ($bodies[$id] ?? '') . $part; },
            static function (?Throwable $error) use ($id, $complete): void { $complete($id, $error); });
    };
    $watchdog = $loop->delay(20.0, static fn() => $loop->stop());
    try {
        $dialer->connect('tcp://127.0.0.1:' . $port,
            static function (?Connection $socket, ?Throwable $error) use (
                $loop, $origin, $policy, $count, $submit, $contract, &$connection, &$transport, &$failure, &$ready,
                &$settled, &$recovered,
            ): void {
                $connection = $socket;
                if ($error !== null || $socket === null) {
                    $failure = $error ?? new RuntimeException('Missing verified socket.');
                    $loop->stop();
                    return;
                }
                $transport = new Http2MultiplexConnection($loop, $socket,
                    static function (Http2MultiplexConnection $client) use ($loop, $origin, $policy, $count, $submit, $contract, &$ready, &$settled, &$recovered, &$failure): void {
                        ++$ready;
                        $complete = static function (int $id, ?Throwable $error) use ($loop, $client, $count, $origin, $policy, $contract, &$settled, &$recovered, &$failure): void {
                            $settled[$id][] = $error;
                            if (count($settled) !== $count) {
                                return;
                            }
                            $loop->defer(static function () use ($loop, $client, $origin, $policy, $contract, &$recovered, &$failure): void {
                                try {
                                    verify($client->activeCount() === 0, 'Completed streams retained admission slots.');
                                    $noop = static function (): void {};
                                    if ($contract === 'goaway') {
                                        expectFailure(static fn() => $client->request(new ClientRequest('GET', $origin . '/recovery', protocol: $policy),
                                            $noop, $noop, $noop), RuntimeException::class, 'GOAWAY admitted a replacement stream.');
                                        $recovered = true;
                                        $client->close();
                                        $loop->stop();
                                        return;
                                    }
                                    expectFailure(static fn() => $client->request(
                                        new ClientRequest('GET', 'https://other.invalid/forbidden', protocol: $policy),
                                        $noop, $noop, $noop), InvalidArgumentException::class, 'Shared connection accepted another origin.');
                                    $response = '';
                                    $headers = [];
                                    $client->request(new ClientRequest('GET', $origin . '/recovery', protocol: $policy),
                                        static function (int $status, Headers $fields) use (&$headers): void { $headers[] = [$status, $fields->first('x-stream-id')]; },
                                        static function (string $part) use (&$response): void { $response .= $part; },
                                        static function (?Throwable $error) use ($loop, $client, &$recovered, &$failure, &$response, &$headers): void {
                                            $recovered = $error === null && $response === 'recovered' && $headers === [[200, 'recovery']];
                                            $failure = $error;
                                            $loop->defer(static function () use ($loop, $client): void { $client->close(); $loop->stop(); });
                                        });
                                } catch (Throwable $error) {
                                    $failure = $error;
                                    $client->close($error);
                                    $loop->stop();
                                }
                            });
                        };
                        for ($id = 1; $id <= $count; ++$id) {
                            $submit($client, $id, $complete);
                        }
                        verify($client->activeCount() === $count, 'Concurrent admission differed.');
                        $noop = static function (): void {};
                        expectFailure(static fn() => $client->request(new ClientRequest('GET', $origin . '/overflow', protocol: $policy),
                            $noop, $noop, $noop), RuntimeException::class, 'Stream limit bypassed.');
                    }, maxConcurrentStreams: $count);
                $transport->start();
            }, new DialOptions(connectTimeoutSeconds: 3.0, tls: new TlsClientOptions(
                '127.0.0.1', caFile: (string) getenv('LAB_RUNWIRE_OUTBOUND_CA'), alpnProtocols: ['h2'])));
        $loop->run();
        echo json_encode([
            'contract' => $contract,
            'ready' => $ready,
            'recovered' => $recovered,
            'settled_streams' => array_keys($settled),
            'failure_type' => $failure === null ? null : $failure::class,
            'failure_message' => $failure?->getMessage(),
        ], JSON_THROW_ON_ERROR), PHP_EOL;
        verify($failure === null && $ready === 1 && $recovered && count($settled) === $count, 'Multiplex exchange did not complete and recover on the same socket.');
        for ($id = 1; $id <= $count; ++$id) {
            verify(($ids[$id] ?? null) === 2 * $id - 1 && count($settled[$id] ?? []) === 1, 'Stream identity or terminal count differs.');
            $error = $settled[$id][0];
            $body = $bodies[$id] ?? '';
            if ($contract === 'goaway' && $id === $count) {
                verify($error instanceof RuntimeException && str_contains($error->getMessage(), 'GOAWAY')
                    && $body === '' && ($statuses[$id] ?? []) === [], 'GOAWAY refused stream exposed a response.');
                continue;
            }
            if ($id === 1 && in_array($contract, ['cancel', 'reset', 'deadline'], true)) {
                $expectedError = match ($contract) {
                    'cancel' => $error instanceof CancelledException && $error->reason === CancellationReason::HOST_CANCELLED,
                    'deadline' => $error instanceof RuntimeException && $error->getMessage() === 'HTTP/2 request deadline expired.',
                    'reset' => $error instanceof RuntimeException && str_contains($error->getMessage(), 'RST_STREAM'),
                };
                verify($expectedError && $body === '', 'Failed stream exposed a body or wrong error: ' . ($error?->getMessage() ?? 'none'));
                verify(($statuses[$id] ?? []) === ($contract === 'reset' ? [] : [[200, '1']]), 'Failed stream header ownership differs.');
                continue;
            }
            $expected = $contract === 'head' && $id % 2 === 1 ? ''
                : ($contract === 'flow' ? str_repeat(chr(64 + $id), 98_304) : "stream-$id");
            verify($error === null && ($statuses[$id] ?? []) === [[200, (string) $id]] && $body === $expected,
                'Sibling response status, headers, or exact bytes changed.');
        }
    } finally {
        if ($cancelTimer !== null) {
            $loop->cancel($cancelTimer);
        }
        $loop->cancel($watchdog);
        $transport?->close();
        $connection?->abort();
        $dialer->close();
        $numeric->close();
        $executor->close();
    }
    $diagnostics = $loop->diagnostics();
    verify($dialer->pendingCount() === 0 && $numeric->pendingCount() === 0 && $diagnostics->readWatchers === 0
        && $diagnostics->writeWatchers === 0 && $diagnostics->timersActive === 0, 'Multiplex ownership leaked.');
    echo "Native H2 $contract with $count concurrent streams and terminal/admission reconciliation passed\n";
}
