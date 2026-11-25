<?php

declare(strict_types=1);

namespace Experiment;

require getenv('PROJECT') . '/vendor/autoload.php';

$base = (string) getenv('LAB_HTTP_URL');
$root = getenv('PROJECT') . '/storage/lab-transfers/';
$host = (string) getenv('HOST_MODE');

$run = static function (string $id, string $mode, bool $head = false) use ($base): array {
    $client = curl_init($base . '/lab/stream-cleanup');
    ensure($client instanceof \CurlHandle, 'Stream client initialization failed.');
    $hash = hash_init('sha256');
    $bytes = 0;
    curl_setopt_array($client, [
        CURLOPT_NOBODY => $head,
        CURLOPT_HTTPHEADER => ['X-Lab-Stream-Id: ' . $id, 'X-Lab-Stream-Mode: ' . $mode],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use ($mode, $hash, &$bytes): int {
            $bytes += strlen($chunk);
            hash_update($hash, $chunk);
            return $mode === 'abort' ? 0 : strlen($chunk);
        },
    ]);
    $result = curl_exec($client);
    ensure(curl_getinfo($client, CURLINFO_RESPONSE_CODE) === 200, 'Unexpected stream status.');
    ensure($mode === 'abort' ? ($result === false && curl_errno($client) === CURLE_WRITE_ERROR && $bytes > 0) : $result === true, 'Client did not reach the intended response boundary.');
    return [$bytes, hash_final($hash)];
};

$terminal = static function (string $id, string $mode) use ($root, $host): array {
    $deadline = hrtime(true) + 15_000_000_000;
    $path = $root . $id . '.terminal';
    do {
        clearstatcache(true, $path);
        if (is_file($path)) {
            break;
        }
        usleep(20000);
    } while (hrtime(true) < $deadline);
    ensure(is_file($path), 'Producer did not publish terminal cleanup.');
    $record = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    ensure(is_int($record['chunks'] ?? null) && ($mode === 'complete' ? $record['chunks'] === 16 : ($record['chunks'] > 0 && $record['chunks'] < 512)), 'Producer did not stop at expected boundary.');
    ensure($record['owner'] === 'generator-finally' || ($mode === 'abort' && $host === 'fpm' && $record['owner'] === 'sapi-shutdown'), 'Unexpected cleanup owner.');
    $lease = fopen($root . $id . '.lease', 'r+b');
    ensure(is_resource($lease) && flock($lease, LOCK_EX | LOCK_NB), 'Request-owned lease still held.');
    flock($lease, LOCK_UN);
    fclose($lease);
    ensure(unlink($root . $id . '.lease') && unlink($path), 'Client fixture cleanup failed.');
    return $record;
};

$head = bin2hex(random_bytes(16));
ensure($run($head, 'complete', true)[0] === 0 && !is_file($root . $head . '.lease') && !is_file($root . $head . '.terminal'), 'HEAD started the streaming producer.');
$expected = hash_init('sha256');
for ($i = 0; $i < 16; ++$i) {
    hash_update($expected, str_repeat(chr($i), 8192));
}
$digest = hash_final($expected);
for ($wave = 0; $wave < 4; ++$wave) {
    $complete = bin2hex(random_bytes(16));
    ensure($run($complete, 'complete') === [16 * 8192, $digest], 'Complete stream bytes differ.');
    $normal = $terminal($complete, 'complete');
    $abort = bin2hex(random_bytes(16));
    $run($abort, 'abort');
    $interrupted = $terminal($abort, 'abort');
    ensure(file_get_contents($base . '/api/health') === '{"status":"ok"}', 'Host did not recover after stream cancellation.');
    echo json_encode(['wave' => $wave, 'complete' => $normal, 'aborted' => $interrupted], JSON_THROW_ON_ERROR), PHP_EOL;
}
// Parallel clients own distinct leases; the parent reconciles the shared directory after all exit.
if (($argv[1] ?? '') !== 'parallel') {
    ensure(glob($root . '*') === [], 'Stream cleanup left staging files.');
}
