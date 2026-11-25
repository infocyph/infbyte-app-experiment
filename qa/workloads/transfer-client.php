<?php

declare(strict_types=1);

namespace Experiment;

require getenv('PROJECT') . '/vendor/autoload.php';

$base = (string) getenv('LAB_HTTP_URL');
$root = (string) getenv('RUNNER_TEMP');
$payloads = [str_repeat("\x00\xFFlab\r\n", 16000), '', str_repeat('attachment', 2000)];
$expected = array_map(static fn(string $bytes): array => ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], $payloads);
$paths = [];
try {
    foreach ([...$payloads, str_repeat('x', 262145)] as $bytes) {
        $path = tempnam($root, 'transfer-');
        ensure(is_string($path), 'Temporary upload creation failed.');
        $paths[] = $path;
        ensure(file_put_contents($path, $bytes, LOCK_EX) === strlen($bytes), 'Temporary upload write failed.');
    }
    $fields = [
        'reference' => 'lab-transfer',
        'document' => new \CURLFile($paths[0], 'application/octet-stream', 'document.bin'),
        'attachments[0]' => new \CURLFile($paths[1], 'application/octet-stream', 'empty.bin'),
        'attachments[1]' => new \CURLFile($paths[2], 'application/octet-stream', 'document.bin'),
    ];
    $upload = static function (array $fields, int $status) use ($base): array {
        $client = curl_init($base . '/lab/transfer');
        ensure($client instanceof \CurlHandle, 'Cannot initialize upload client.');
        curl_setopt_array($client, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Expect:', 'Accept: application/json'],
        ]);
        $body = curl_exec($client);
        ensure(is_string($body) && curl_getinfo($client, CURLINFO_RESPONSE_CODE) === $status, 'Unexpected multipart response.');
        $result = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        ensure(is_array($result), 'Multipart response is not JSON.');

        return $result;
    };
    $missing = $fields;
    unset($missing['attachments[1]']);
    ensure($upload($missing, 422) === ['error' => 'invalid multipart'], 'Missing attachment accepted.');
    $oversized = $fields;
    $oversized['document'] = new \CURLFile($paths[3], 'application/octet-stream', 'oversized.bin');
    ensure($upload($oversized, 422) === ['error' => 'invalid file'], 'Oversized document accepted.');
    for ($i = 0; $i < 12; ++$i) {
        ensure($upload($fields, 201) === ['files' => $expected], 'Upload content reconciliation failed.');
    }

    $expectedStream = '';
    for ($sequence = 1; $sequence <= 64; ++$sequence) {
        $expectedStream .= "id: $sequence\nevent: progress\ndata: {\"sequence\":$sequence}\n\n";
    }
    $client = curl_init($base . '/lab/events');
    ensure($client instanceof \CurlHandle, 'Cannot initialize SSE client.');
    curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15]);
    ensure(curl_exec($client) === $expectedStream && curl_getinfo($client, CURLINFO_RESPONSE_CODE) === 200
        && str_starts_with((string) curl_getinfo($client, CURLINFO_CONTENT_TYPE), 'text/event-stream'), 'SSE order, content or media type mismatch.');

    $abort = curl_init($base . '/lab/events');
    ensure($abort instanceof \CurlHandle, 'Cannot initialize abort client.');
    curl_setopt_array($abort, [
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15,
        CURLOPT_WRITEFUNCTION => static fn(\CurlHandle $handle, string $chunk): int => 0,
    ]);
    ensure(curl_exec($abort) === false && curl_errno($abort) === CURLE_WRITE_ERROR
        && curl_getinfo($abort, CURLINFO_RESPONSE_CODE) === 200, 'Client did not interrupt SSE response.');
    ensure($upload($fields, 201) === ['files' => $expected], 'Host failed to serve upload after client abort.');
    ensure(glob(getenv('PROJECT') . '/storage/lab-transfers/*') === [], 'Upload staging files leaked.');
    echo "Multipart binary/empty/nested files, move ownership, input rejection, ordered SSE and post-abort recovery passed\n";
} finally {
    foreach ($paths as $path) {
        unlink($path);
    }
}
