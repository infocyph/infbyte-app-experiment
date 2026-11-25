<?php

declare(strict_types=1);

use Infocyph\Runwire\FpmOptions;
use Infocyph\Runwire\FrankenPhpOptions;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\RoadRunnerOptions;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\FrankenPhpMode;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\SwooleOptions;

$project = dirname(__DIR__);
require $project . '/vendor/autoload.php';

$driver = match (true) {
    function_exists('frankenphp_handle_request') => RuntimeDriver::FRANKENPHP,
    PHP_SAPI === 'fpm-fcgi' => RuntimeDriver::FPM,
    ($argv[1] ?? '') === 'roadrunner' => RuntimeDriver::ROADRUNNER,
    ($argv[1] ?? '') === 'swoole' && extension_loaded('swoole'),
    ($argv[1] ?? '') === 'openswoole' && extension_loaded('openswoole') => RuntimeDriver::SWOOLE,
    default => throw new LogicException('Actual host runtime required.'),
};
$root = $project . '/storage/lab-host-budget';
$limit = 4096;
$require = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$runtime = Runtime::create(new RuntimeOptions(
    driver: $driver,
    fpm: new FpmOptions($limit, $limit),
    frankenPhp: new FrankenPhpOptions(FrankenPhpMode::WORKER, $limit, $limit),
    roadRunner: new RoadRunnerOptions($limit, $limit),
    swoole: new SwooleOptions(host: '127.0.0.1', port: 18781, workerCount: 2, maxRequestBodyBytes: $limit, maxResponseBytes: $limit),
));
$runtime->serve(static function (HttpRequest $request, ResponseWriterInterface $writer) use ($root, $limit, $require): void {
    $path = $request->target;
    $require(preg_match('~^/(?:health|upload|single|split|overflow|head)(?:-[a-z0-9]+)?$~D', $path) === 1, 'Invalid fixture target.');
    $kind = explode('-', substr($path, 1), 2)[0];
    $entered = ['target' => $path, 'pid' => getmypid(), 'method' => $request->method];
    $require(file_put_contents($root . '/entered.jsonl', json_encode($entered, JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX) !== false, 'Handler entry evidence unavailable.');
    $body = str_repeat(hash('sha256', 'host-budget', true), 128);
    $terminal = 0;
    $writer->onTerminal(static function () use (&$terminal): void {
        ++$terminal;
    });
    if ($kind === 'upload') {
        $input = $request->body->read();
        $require($request->body->eof() && strlen($input) === $request->body->receivedBytes(), 'Host body normalization lost bytes.');
        $require($writer->end(hash('sha256', $input))->accepted(), 'Upload digest response failed.');
    } elseif ($kind === 'health') {
        $require($writer->end('healthy')->accepted(), 'Health response failed.');
    } elseif ($kind === 'head') {
        $require($request->method === 'HEAD', 'HEAD normalized to another method.');
        $require($writer->start(200, Headers::fromArray(['Content-Length' => (string) ($limit + 1)]))->accepted(), 'HEAD metadata rejected.');
        $require($writer->end($body . 'x')->accepted(), 'Suppressed HEAD body charged against response limit.');
    } else {
        $require($writer->start(200, Headers::fromArray(['Content-Type' => 'application/octet-stream']))->accepted(), 'Response start rejected.');
        if ($kind === 'single') {
            $require($writer->end($body)->accepted(), 'Exact response rejected.');
        } else {
            $require($writer->write(substr($body, 0, 2048))->accepted(), 'First bounded chunk rejected.');
            if ($kind === 'overflow') {
                $require($writer->end(str_repeat('x', 2049))->state === WriteState::REJECTED_LIMIT, 'Aggregate over-limit final chunk accepted.');
                $require(!$writer->isEnded(), 'Rejected final chunk prematurely ended response.');
            }
            $require($writer->write(substr($body, 2048, 1024))->accepted(), 'Second bounded chunk rejected.');
            $require($writer->end(substr($body, 3072))->accepted(), 'Exact aggregate response rejected.');
        }
    }
    $require($writer->isEnded() && $terminal === 1 && $writer->write('late')->state === WriteState::CLOSED, 'Terminal writer ownership differs.');
    $require(file_put_contents($root . '/terminal.jsonl', json_encode($entered, JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX) !== false, 'Terminal evidence unavailable.');
});
