<?php

declare(strict_types=1);

use Infocyph\Runwire\Control\ControlOptions;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
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
$runtime = Runtime::create(new RuntimeOptions(
    driver: RuntimeDriver::NATIVE,
    workerRecycle: new WorkerRecyclePolicy(maxRequests: $mode === 'supervisor' ? 20 : 0),
));
$server = new Server('boundary', '127.0.0.1:18786', $handler, workers: 2, http1: new Http1Limits(
    maxRequestLineBytes: 1024,
    maxHeaderLineBytes: 1024,
    maxHeaderBytes: 4096,
    maxHeaderCount: 24,
    maxBodyBytes: 65536,
    headerTimeoutSeconds: 2.0,
    bodyIdleTimeoutSeconds: 2.0,
));
$runtime->listen($server);
if ($mode === 'supervisor') {
    $runtime->control(new ControlOptions($root . '/control.sock', maxRequestBytes: 512));
}
$runtime->run();
