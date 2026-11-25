<?php

declare(strict_types=1);

$root = getenv('PROJECT');
require $root . '/vendor/autoload.php';

use function Experiment\ensure;

$prefix = $argv[1] ?? '';
$iterations = (int) ($argv[2] ?? 0);
$endpoint = getenv('LAB_HTTP_URL');
$buyer = file_get_contents($root . '/storage/lab-private/buyer');
$foreign = file_get_contents($root . '/storage/lab-private/foreign');
ensure(is_string($buyer) && is_string($foreign) && is_string($endpoint), 'Missing private request fixtures.');
$curl = curl_init();
ensure($curl instanceof CurlHandle, 'Unable to open HTTP client.');

$request = static function (string $method, string $id, ?string $token, int $expected) use ($curl, $endpoint): array {
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($curl, [
        CURLOPT_URL => $endpoint . '/api/lab/orders/' . $id, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    ensure(is_string($body) && $status === $expected, 'HTTP status mismatch: expected ' . $expected . ', received ' . $status);
    $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    ensure(is_array($payload), 'Unexpected HTTP response.');

    return $payload;
};

for ($i = 0; $i < $iterations; ++$i) {
    $id = $prefix . '-' . $i;
    $start = hrtime(true);
    $request('POST', $id, null, 401);
    $request('POST', $id, $foreign, 403);
    ensure($request('POST', $id, $buyer, 201) === ['id' => $id, 'amount' => 300], 'Write response mismatch.');
    ensure($request('GET', $id, $buyer, 200) === ['id' => $id, 'amount' => 300], 'Read response mismatch.');
    $request('GET', $id, $foreign, 403);
    echo json_encode(['id' => $id, 'milliseconds' => (hrtime(true) - $start) / 1e6], JSON_THROW_ON_ERROR), PHP_EOL;
}
