<?php

declare(strict_types=1);

$expected = (int) ($argv[1] ?? 0);
$elapsed = (int) ($argv[2] ?? 0);
$concurrency = (int) ($argv[3] ?? 0);
$times = [];
$ids = [];
foreach (array_slice($argv, 4) as $path) {
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException('Missing worker evidence.');
    }
    try {
        while (($line = fgets($stream)) !== false) {
            if (trim($line) === '') {
                throw new RuntimeException('Empty operation evidence.');
            }
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (!is_string($row['id'] ?? null) || isset($ids[$row['id']]) || $row['id'] === '' || !is_int($row['milliseconds'] ?? null) && !is_float($row['milliseconds'] ?? null) || !is_finite((float) $row['milliseconds']) || $row['milliseconds'] < 0) {
                throw new RuntimeException('Invalid or duplicate operation evidence.');
            }
            $ids[$row['id']] = true;
            $times[] = (float) $row['milliseconds'];
        }
        if (!feof($stream)) {
            throw new RuntimeException('Worker evidence read failed.');
        }
    } finally {
        fclose($stream);
    }
}
if (count($times) !== $expected || $elapsed <= 0 || $expected <= 0 || $concurrency <= 0) {
    throw new RuntimeException(sprintf('Incomplete operation evidence: expected=%d, received=%d, elapsed_ms=%d.', $expected, count($times), $elapsed));
}
sort($times, SORT_NUMERIC);
$percentile = static fn(float $p): float => $times[(int) ceil(count($times) * $p) - 1];
echo json_encode([
    'concurrency' => $concurrency,
    'successful_operations' => $expected,
    'wall_ms' => $elapsed,
    'successful_operations_per_minute' => $expected * 60000 / $elapsed,
    'p50_ms' => $percentile(0.50),
    'p95_ms' => $percentile(0.95),
    'p99_ms' => $percentile(0.99),
    'max_ms' => max($times),
    'scope' => 'Shared hosted runner diagnostic; not production throughput certification.',
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
