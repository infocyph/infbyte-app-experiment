<?php

declare(strict_types=1);

require getenv('PROJECT') . '/vendor/autoload.php';

use function Experiment\ensure;

$tokens = [];
foreach (['alpha', 'beta', 'denied'] as $tenant) {
    $tokens[$tenant] = file_get_contents(getenv('PROJECT') . '/storage/lab-private/' . $tenant);
    ensure(is_string($tokens[$tenant]) && $tokens[$tenant] !== '', 'Private token unavailable.');
}
$curl = curl_init();
$request = static function (string $method, string $path, ?string $token, int $status) use ($curl): array {
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($curl, [CURLOPT_URL => 'http://127.0.0.1:18781/api/tenant/' . $path, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $body = curl_exec($curl);
    ensure(is_string($body) && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === $status, 'Unexpected tenant response status.');
    return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
};
for ($i = 0; $i < 20; ++$i) {
    $key = 'worker-' . $argv[1] . '-' . $i;
    $orders = [];
    foreach (['alpha', 'beta'] as $tenant) {
        $other = $tenant === 'alpha' ? 'beta' : 'alpha';
        $path = $tenant . '/checkout/' . $key;
        $request('POST', $path, null, 401);
        $request('POST', $path, $tokens[$other], 403);
        $request('POST', $path, $tokens['denied'], 403);
        $order = $request('POST', $path, $tokens[$tenant], 201);
        ensure($order['created'] === true, 'Missing creation result.');
        $replay = $request('POST', $path, $tokens[$tenant], 200);
        ensure($replay['created'] === false && $replay['id'] === $order['id'], 'Idempotency mismatch.');
        $orders[$tenant] = $order['id'];
        ensure($request('GET', $tenant . '/orders/' . $order['id'], $tokens[$tenant], 200) === ['id' => $order['id'], 'tenant' => $tenant], 'Tenant read mismatch.');
        $request('GET', $tenant . '/orders/' . $order['id'], $tokens[$other], 403);
        $request('GET', $other . '/orders/' . $order['id'], $tokens[$other], 404);
    }
    ensure($orders['alpha'] !== $orders['beta'], 'Tenant identifiers collided.');
    echo json_encode(['request' => $key, 'orders' => $orders], JSON_THROW_ON_ERROR), PHP_EOL;
}
