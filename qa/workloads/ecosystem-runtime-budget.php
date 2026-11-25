<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http1\Http1Connection;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\StreamingRequestBody;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\LoopFactory;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Metrics\DiagnosticsPolicy;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\Internal\ByteBudget;

function workerBudgetTick(LoopInterface $loop, float $seconds = 0.1): void
{
    $loop->delay($seconds, static fn() => $loop->stop());
    $loop->run();
}

function workerBudgetLimits(): ConnectionLimits
{
    return new ConnectionLimits(
        readChunkBytes: 1024, maxReadBytesPerTick: 2048,
        receiveLowWatermarkBytes: 128, receiveHighWatermarkBytes: 768, maxReceiveBufferBytes: 1024,
        sendLowWatermarkBytes: 128, sendHighWatermarkBytes: 512, maxSendBufferBytes: 1024, maxWriteBytesPerTick: 1024,
    );
}

function workerBudgetCompetition(LoopInterface $loop): void
{
    $budget = new ByteBudget(1024);
    $connections = $clients = $bodies = [];
    try {
        for ($i = 0; $i < 2; ++$i) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            verify(is_array($pair), 'Socket pair unavailable.');
            $clients[] = $pair[1];
            $connection = new Connection($loop, $pair[0], workerBudgetLimits(), bufferBudget: $budget);
            $connections[] = $connection;
            new Http1Connection($loop, $connection, new Http1Limits(
                maxBodyBytes: 4096, bodyLowWatermarkBytes: 128, bodyHighWatermarkBytes: 900,
                maxPendingBodyBytes: 1024, bodyIdleTimeoutSeconds: 2.0,
            ), static function (HttpRequest $request) use (&$bodies): void {
                verify($request->body instanceof StreamingRequestBody, 'Expected streaming body.');
                $bodies[] = $request->body;
            });
        }
        $wire = "POST /hold HTTP/1.1\r\nHost: lab\r\nContent-Length: 700\r\n\r\n";
        verify(fwrite($clients[0], $wire . str_repeat('a', 600)) === strlen($wire) + 600, 'First fixture write incomplete.');
        workerBudgetTick($loop);
        verify(count($bodies) === 1 && $bodies[0]->bufferedBytes() === 600 && $budget->used() >= 600, 'First upload was not retained and charged.');
        verify(fwrite($clients[1], $wire . str_repeat('b', 600)) === strlen($wire) + 600, 'Second fixture write incomplete.');
        workerBudgetTick($loop);
        verify(count($bodies) === 2, 'Second request did not dispatch.');
        $retained = array_sum(array_map(static fn(StreamingRequestBody $body): int => $body->bufferedBytes(), $bodies));
        verify($retained >= 600 && $retained <= 1024 && $budget->used() >= $retained && $budget->used() <= 1024, 'Aggregate worker budget not conserved.');
    } finally {
        foreach ($bodies as $body) {
            $body->discardBuffered();
        }
        foreach ($connections as $connection) {
            $connection->abort();
        }
        foreach ($clients as $client) {
            fclose($client);
        }
    }
    verify($budget->used() === 0 && $budget->available() === 1024, 'Abort leaked worker budget.');
}

function workerBudgetTerminal(LoopInterface $loop, string $mode): void
{
    $budget = new ByteBudget(2048);
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    verify(is_array($pair), 'Socket pair unavailable.');
    $connection = new Connection($loop, $pair[0], workerBudgetLimits(), bufferBudget: $budget);
    $received = '';
    $entered = 0;
    try {
        new Http1Connection($loop, $connection, new Http1Limits(
            maxBodyBytes: 2048, bodyLowWatermarkBytes: 128, bodyHighWatermarkBytes: 512,
            maxPendingBodyBytes: 1024, bodyIdleTimeoutSeconds: 2.0,
        ), static function (HttpRequest $request, ResponseWriterInterface $writer) use ($mode, &$received, &$entered): void {
            ++$entered;
            if ($mode === 'early') {
                $writer->end('early');
            } elseif ($mode === 'complete') {
                $request->body->onData(static function ($body) use (&$received): void {
                    $received .= $body->read();
                });
                $request->body->onEnd(static fn() => $writer->end('ok'));
            }
        });
        $payload = str_repeat('r', $mode === 'eof' ? 300 : 900);
        $wire = "POST /terminal HTTP/1.1\r\nHost: lab\r\nContent-Length: 900\r\nConnection: close\r\n\r\n" . $payload;
        verify(fwrite($pair[1], $wire) === strlen($wire), 'Terminal fixture write incomplete.');
        if ($mode === 'eof') {
            stream_socket_shutdown($pair[1], STREAM_SHUT_WR);
        }
        workerBudgetTick($loop, 0.2);
        verify($entered === 1, 'Terminal scenario did not dispatch.');
        verify($mode !== 'complete' || $received === $payload, 'Complete request lost body bytes.');
        verify($budget->used() === 0, 'Terminal path retained worker bytes: ' . $mode);
    } finally {
        $connection->abort();
        fclose($pair[1]);
    }
    verify($budget->used() === 0, 'Connection disposal leaked budget.');
}

function workerBudgetWorkload(): void
{
    $factories = [
        'select' => static fn(): LoopInterface => new SelectLoop(),
        'native' => static fn(): LoopInterface => LoopFactory::native(new DiagnosticsPolicy()),
    ];
    foreach ($factories as $name => $make) {
        for ($wave = 0; $wave < 8; ++$wave) {
            workerBudgetCompetition($make());
            foreach (['complete', 'early', 'eof'] as $mode) {
                workerBudgetTerminal($make(), $mode);
            }
        }
        echo "$name: eight competition and terminal recovery waves conserved worker-owned bytes\n";
    }
}
