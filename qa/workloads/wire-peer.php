<?php

declare(strict_types=1);

use Infocyph\Runwire\DatagramServer;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Datagram;
use Infocyph\Runwire\Network\DatagramListener;
use Infocyph\Runwire\Network\DatagramOptions;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Protocol\FramedConnection;
use Infocyph\Runwire\Protocol\LineCodec;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\StreamServer;
use Infocyph\Runwire\WebSocket\WebSocketMessage;
use Infocyph\Runwire\WebSocket\WebSocketSession;
use Infocyph\Runwire\WebSocket\WebSocketUpgrade;

require getenv('PROJECT') . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
$root = (string) getenv('LAB_WIRE_ROOT');
$codec = static fn(): LineCodec => new LineCodec(maxFrameBytes: 4096);
$echo = static function (string $frame, FramedConnection $connection): void {
    if (!$connection->send('echo:' . $frame)->accepted()) {
        $connection->abort();
    }
};
$http = static function (HttpRequest $request, ResponseWriterInterface $writer): void {
    if ($request->target === '/ws') {
        $session = WebSocketUpgrade::accept($request, $writer, originPolicy: static fn(string $origin): bool => $origin === 'https://lab.example');
        if ($session instanceof WebSocketSession) {
            $session->onMessage(static function (WebSocketSession $session, WebSocketMessage $message): void {
                $result = $message->binary ? $session->sendBinary($message->data) : $session->sendText($message->data);
                if (!$result->accepted()) {
                    $session->close(1011, 'write rejected');
                }
            });
        }

        return;
    }
    $body = $request->version->value . ':' . $request->target;
    $writer->start(200, Headers::fromArray(['Content-Length' => (string) strlen($body), 'Content-Type' => 'text/plain']));
    $writer->end($body);
};
if (in_array($mode, ['http2-flow', 'http3-flow'], true)) {
    $ordinary = $http;
    $http = static function (HttpRequest $request, ResponseWriterInterface $writer) use ($root, $ordinary, $mode): void {
        $target = $request->target;
        if ($target === '/flow-upload') {
            $request->context->cancellation->onCancel(static function (CancellationToken $token) use ($root): void {
                file_put_contents($root . '/upload-cancel', $token->reason()?->value . PHP_EOL, FILE_APPEND | LOCK_EX);
            });
            file_put_contents($root . '/upload-ready', '1', LOCK_EX);
            return;
        }
        if (!in_array($target, ['/flow-stream', '/flow-cancel'], true)) {
            $ordinary($request, $writer);
            return;
        }
        $cancel = $target === '/flow-cancel';
        if ($cancel) {
            $request->context->cancellation->onCancel(static function (CancellationToken $token) use ($root): void {
                file_put_contents($root . '/response-cancel', $token->reason()?->value . PHP_EOL, FILE_APPEND | LOCK_EX);
            });
        }
        $size = 4096;
        $count = 64;
        $index = $pressure = $drains = $peak = 0;
        if (!$writer->start(200, Headers::fromArray(['Content-Length' => (string) ($size * $count)]))->accepted()) {
            throw new RuntimeException('Flow test response start rejected.');
        }
        if ($cancel && $mode === 'http3-flow') {
            // Keep a genuinely incomplete response until the peer cancels its receive direction.
            if (!$writer->write(str_repeat(chr(0), $size))->accepted()) {
                throw new RuntimeException('Partial QUIC response rejected.');
            }
            return;
        }
        if (!$cancel) {
            $writer->onTerminal(static function () use ($root, &$index, &$pressure, &$drains, &$peak): void {
                file_put_contents($root . '/flow-terminal', json_encode(['chunks' => $index, 'pressure' => $pressure, 'drains' => $drains, 'peak' => $peak], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
            });
        }
        $pump = static function (ResponseWriterInterface $response) use ($size, $count, &$index, &$pressure, &$peak): void {
            while ($index < $count) {
                $result = $response->write(str_repeat(chr($index), $size));
                $peak = max($peak, $result->bufferedBytes);
                if (!$result->accepted()) {
                    throw new RuntimeException('Flow test bounded write rejected: ' . $result->state->value);
                }
                ++$index;
                if ($result->pressured()) {
                    ++$pressure;
                    return;
                }
            }
            if (!$response->end()->accepted()) {
                throw new RuntimeException('Flow test response completion rejected.');
            }
        };
        $writer->onDrain(static function (ResponseWriterInterface $response) use ($pump, &$drains): void {
            ++$drains;
            $pump($response);
        });
        $pump($writer);
    };
}
if (in_array($mode, ['http2-admission', 'http3-admission'], true)) {
    $ordinary = $http;
    $http = static function (HttpRequest $request, ResponseWriterInterface $writer) use ($ordinary, $root): void {
        $target = $request->target;
        file_put_contents($root . '/admitted', $target . PHP_EOL, FILE_APPEND | LOCK_EX);
        if (!str_starts_with($target, '/hold/')) {
            $ordinary($request, $writer);
            return;
        }
        $request->context->cancellation->onCancel(static function () use ($root, $target): void {
            file_put_contents($root . '/cancelled', $target . PHP_EOL, FILE_APPEND | LOCK_EX);
        });
        $body = $request->body;
        $body->onData(static function ($body): void {
            while ($body->bufferedBytes() > 0) {
                $body->read(8192);
            }
        });
        $finish = static function () use ($ordinary, $request, $writer): void {
            $ordinary($request, $writer);
        };
        if ($body->eof()) {
            $finish();
        } else {
            $body->onEnd($finish);
        }
    };
}
if (str_starts_with($mode, 'http2-aggregate-')) {
    $ordinary = $http;
    $http = static function (HttpRequest $request, ResponseWriterInterface $writer) use ($ordinary, $root): void {
        if (preg_match('~^/aggregate/([a-z0-9-]+)$~D', $request->target, $match) !== 1) {
            $ordinary($request, $writer);
            return;
        }
        $id = $match[1];
        $request->context->cancellation->onCancel(static function () use ($root, $id): void {
            file_put_contents($root . '/aggregate-cancel-' . $id, 'cancelled', LOCK_EX);
        });
        if (!$writer->start(200, Headers::fromArray(['Content-Type' => 'application/octet-stream']))->accepted()) {
            throw new RuntimeException('Aggregate fixture header write rejected.');
        }
        $body = str_repeat(hash('sha256', $id, true), 768);
        $result = $writer->write($body);
        $record = [
            'accepted' => $result->accepted(),
            'state' => $result->state->value,
            'buffered' => $result->bufferedBytes,
            'length' => strlen($body),
            'sha256' => hash('sha256', $body),
        ];
        if (!$writer->end()->accepted()) {
            throw new RuntimeException('Aggregate fixture terminal write rejected.');
        }
        if (file_put_contents($root . '/aggregate-' . $id, json_encode($record, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Aggregate fixture evidence unavailable.');
        }
    };
}
$server = match ($mode) {
    'tcp' => StreamServer::tcp('127.0.0.1:18785', $codec, $echo),
    'unix' => StreamServer::unix($root . '/peer.sock', $codec, $echo),
    'udp' => new DatagramServer('udp', '127.0.0.1:18785', static function (Datagram $datagram, DatagramListener $listener): void {
        $listener->sendTo($datagram->payload, $datagram->peerAddress);
    }, options: new DatagramOptions(maxDatagramBytes: 512)),
    'websocket' => Server::http('127.0.0.1:18785', $http),
    'http3' => (new Server('quic', '127.0.0.1:18785', $http, tls: new TlsOptions($root . '/cert.pem', $root . '/key.pem')))->withHttp3(),
    'http3-flow' => (new Server('quic-flow', '127.0.0.1:18785', $http, tls: new TlsOptions($root . '/cert.pem', $root . '/key.pem')))->withHttp3(new Http3Options(limits: new Http3Limits(maxPendingResponseBytesPerStream: 32768, responseLowWatermarkBytes: 8192, responseHighWatermarkBytes: 16384, maxPendingResponseBytesPerConnection: 65536, maxQpackEncoderQueueBytes: 16384, maxResponseFramePayloadBytes: 4096))),
    'mtls' => new Server('mutual-tls', '127.0.0.1:18785', $http, tls: new TlsOptions($root . '/cert.pem', $root . '/key.pem', extraContext: ['cafile' => $root . '/ca.pem'], requireClientCertificate: true)),
    'http2-admission' => new Server('h2-admission', '127.0.0.1:18785', $http, tls: new TlsOptions($root . '/cert.pem', $root . '/key.pem')),
    'http3-admission' => (new Server('h3-admission', '127.0.0.1:18785', $http, tls: new TlsOptions($root . '/cert.pem', $root . '/key.pem')))->withHttp3(),
    'http2' => new Server('tls', '127.0.0.1:18785', $http, tls: new TlsOptions($root . '/cert.pem', $root . '/key.pem')),
    'http2-aggregate-drain', 'http2-aggregate-reset', 'http2-aggregate-isolation' => new Server('h2-aggregate', '127.0.0.1:18785', $http, tls: new TlsOptions($root . '/cert.pem', $root . '/key.pem'), http2: new Http2Limits(maxPendingResponseBytesPerStream: 32768, responseLowWatermarkBytes: 8192, responseHighWatermarkBytes: 16384, maxPendingResponseBytesPerConnection: 65536, streamIdleTimeoutSeconds: 30.0)),
    'http2-flow' => new Server('tls-flow', '127.0.0.1:18785', $http, tls: new TlsOptions($root . '/cert.pem', $root . '/key.pem'), http2: new Http2Limits(maxPendingResponseBytesPerStream: 32768, responseLowWatermarkBytes: 8192, responseHighWatermarkBytes: 16384, maxPendingResponseBytesPerConnection: 65536, streamIdleTimeoutSeconds: 15.0)),
    default => throw new InvalidArgumentException('Unknown wire workload.'),
};
$runtime = in_array($mode, ['http2-admission', 'http3-admission'], true)
    ? Runtime::create(new \Infocyph\Runwire\RuntimeOptions(
        driver: \Infocyph\Runwire\Runtime\Enum\RuntimeDriver::NATIVE,
        admission: new \Infocyph\Runwire\Runtime\AdmissionPolicy(maxActiveRequests: 32, maxStreamsPerWorker: 2, retryAfterSeconds: 3),
    ))
    : Runtime::create();
$runtime->listen($server)->run();
