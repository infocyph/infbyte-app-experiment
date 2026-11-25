<?php

declare(strict_types=1);

require __DIR__ . '/helpers.php';

use function Experiment\ensure;

$phase = (int) $argv[1];
$worker = (int) $argv[2];
$seconds = (int) $argv[3];
$maximum = (int) $argv[4];
$tenant = ($worker - 1) % 8 + 1;
$deadline = hrtime(true) + $seconds * 1_000_000_000;
$handle = curl_init();
ensure($handle instanceof CurlHandle, 'Cannot initialize HTTP client.');
curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15]);
$exchange = static function (string $method, string $url, int $expected) use ($handle): array {
    curl_setopt_array($handle, [CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method]);
    $body = curl_exec($handle);
    ensure(is_string($body) && curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === $expected, 'Unexpected HTTP result.');

    return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
};
for ($i = 0; $i < $maximum && hrtime(true) < $deadline; ++$i) {
    $id = 'p' . $phase . '-w' . $worker . '-' . $i;
    $url = 'http://127.0.0.1:18783/api/endurance/' . $tenant . '/' . $id;
    $start = hrtime(true);
    $first = $exchange('POST', $url, 201);
    $replay = $exchange('POST', $url, 200);
    $read = $exchange('GET', $url, 200);
    ensure($first === ['id' => $id, 'tenant' => $tenant, 'created' => true]
        && $replay === ['id' => $id, 'tenant' => $tenant, 'created' => false]
        && $read === ['id' => $id, 'tenant' => $tenant], 'Business response mismatch.');
    echo json_encode(['id' => $id, 'tenant' => $tenant, 'milliseconds' => (hrtime(true) - $start) / 1e6], JSON_THROW_ON_ERROR), PHP_EOL;
    // Deliberate bounded offered load; not a saturation-throughput claim.
    if ($phase !== 0) {
        usleep(250000);
    }
}
