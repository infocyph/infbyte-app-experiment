<?php

declare(strict_types=1);

use Infocyph\Runwire\Control\ControlOptions;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\AdmissionPolicy;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

require getenv('PROJECT') . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
$root = (string) getenv('LAB_BOUNDARY_ROOT');
$handler = static function (HttpRequest $request, ResponseWriterInterface $writer): void {
    $body = $request->body;
    $digest = hash_init('sha256');
    $consume = static function (RequestBodyInterface $source) use ($digest): void {
        while ($source->bufferedBytes() > 0) {
            hash_update($digest, $source->read(8192));
        }
    };
    $done = false;
    $finish = static function (RequestBodyInterface $source) use ($consume, $digest, $request, $writer, &$done): void {
        $consume($source);
        if ($done || !$source->eof()) {
            return;
        }
        $done = true;
        $response = json_encode([
            'target' => $request->target,
            'pid' => getmypid(),
            'bytes' => $source->receivedBytes(),
            'digest' => hash_final($digest),
        ], JSON_THROW_ON_ERROR);
        $writer->start(200, Headers::fromArray(['Content-Type' => 'application/json', 'Content-Length' => (string) strlen($response)]));
        $writer->end($response);
    };
    $body->onData($consume);
    $body->onEnd($finish);
    $finish($body);
};
if ($mode === 'http-backpressure') {
    $ordinary = $handler;
    $handler = static function (HttpRequest $request, ResponseWriterInterface $writer) use ($root, $ordinary): void {
        if ($request->target === '/aborted-upload') {
            $request->context->cancellation->onCancel(static function (CancellationToken $token) use ($root): void {
                file_put_contents($root . '/cancelled.log', $token->reason()?->value . PHP_EOL, FILE_APPEND | LOCK_EX);
            });
            file_put_contents($root . '/upload-ready', '1', LOCK_EX);
            return;
        }
        if ($request->target !== '/pressured-stream') {
            $ordinary($request, $writer);
            return;
        }

        $size = 8192;
        $count = 128;
        $index = $pressure = $drains = $peak = 0;
        $start = $writer->start(200, Headers::fromArray([
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) ($size * $count),
        ]));
        if (!$start->accepted()) {
            throw new RuntimeException('Response headers rejected.');
        }
        $oversized = $writer->write(str_repeat('x', $size + 1));
        if ($oversized->state !== WriteState::REJECTED_LIMIT) {
            throw new RuntimeException('Oversized response write was not rejected.');
        }
        $writer->onTerminal(static function () use ($root, &$index, &$pressure, &$drains, &$peak): void {
            file_put_contents($root . '/terminal.log', json_encode([
                'chunks' => $index, 'pressure' => $pressure, 'drains' => $drains, 'peak' => $peak,
            ], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
        });
        $pump = static function (ResponseWriterInterface $response) use ($size, $count, &$index, &$pressure, &$peak): void {
            while ($index < $count) {
                $chunk = str_repeat(chr($index % 256), $size);
                $result = $response->write($chunk);
                $peak = max($peak, $result->bufferedBytes);
                if (!$result->accepted()) {
                    throw new RuntimeException('Bounded chunk rejected: ' . $result->state->value);
                }
                ++$index;
                if ($result->pressured()) {
                    ++$pressure;
                    return;
                }
            }
            if (!$response->end()->accepted()) {
                throw new RuntimeException('Response completion rejected.');
            }
        };
        $writer->onDrain(static function (ResponseWriterInterface $response) use ($pump, &$drains): void {
            ++$drains;
            $pump($response);
        });
        $pump($writer);
    };
}
if (in_array($mode, ['http-admission', 'http-connection-quotas'], true)) {
    $ordinary = $handler;
    $handler = static function (HttpRequest $request, ResponseWriterInterface $writer) use ($root, $ordinary): void {
        if (str_starts_with($request->target, '/quota-')) {
            file_put_contents($root . '/admitted.log', $request->target . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        if (str_starts_with($request->target, '/quota-hold/')) {
            $request->context->cancellation->onCancel(static function () use ($root, $request): void {
                file_put_contents($root . '/quota-cancel.log', $request->target . PHP_EOL, FILE_APPEND | LOCK_EX);
            });
        }
        $ordinary($request, $writer);
    };
}
$runtime = Runtime::create(new RuntimeOptions(
    driver: RuntimeDriver::NATIVE,
    admission: match ($mode) {
        'http-admission' => new AdmissionPolicy(maxActiveRequests: 2, retryAfterSeconds: 2),
        'http-connection-quotas' => new AdmissionPolicy(maxConcurrentConnections: 2),
        default => new AdmissionPolicy(),
    },
    workerRecycle: new WorkerRecyclePolicy(maxRequests: $mode === 'supervisor' ? 20 : 0),
));
$server = new Server('boundary', '127.0.0.1:18786', $handler, workers: in_array($mode, ['http-backpressure', 'http-admission', 'http-connection-quotas'], true) ? 1 : 2, connection: match ($mode) {
    'http-backpressure' => new ConnectionLimits(sendLowWatermarkBytes: 1024, sendHighWatermarkBytes: 4096, maxSendBufferBytes: 16384, maxWriteBytesPerTick: 4096),
    'http-connection-quotas' => new ConnectionLimits(idleTimeoutSeconds: 3.0),
    default => new ConnectionLimits(),
}, http1: new Http1Limits(
    maxRequestLineBytes: 1024,
    maxHeaderLineBytes: 1024,
    maxHeaderBytes: 4096,
    maxHeaderCount: 24,
    maxBodyBytes: 65536,
    maxKeepAliveRequests: $mode === 'http-connection-quotas' ? 3 : 1000,
    maxResponseChunkBytes: $mode === 'http-backpressure' ? 8192 : 65536,
    // In the idle-quota case, let the 3-second transport idle deadline win.
    // Other cases keep the separate 2-second HTTP header deadline.
    headerTimeoutSeconds: $mode === 'http-connection-quotas' ? 5.0 : 2.0,
    bodyIdleTimeoutSeconds: $mode === 'http-admission' ? 15.0 : 2.0,
));
$runtime->listen($server);
if ($mode === 'supervisor') {
    $runtime->control(new ControlOptions($root . '/control.sock', maxRequestBytes: 512));
}
$runtime->run();
