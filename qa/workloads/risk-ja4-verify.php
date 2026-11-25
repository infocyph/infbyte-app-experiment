<?php

declare(strict_types=1);

$observed = file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($observed === false || count($observed) !== 4 || !str_starts_with($observed[0], 't12d')
    || !str_starts_with($observed[1], 't13d') || count(array_unique(array_slice($observed, 1))) !== 1) {
    throw new RuntimeException('TLS profiles were not independently observed.');
}
$logs = file($argv[2], FILE_IGNORE_NEW_LINES);
if ($logs === false) {
    throw new RuntimeException('Proxy evidence missing.');
}
$matched = [];
foreach ($logs as $line) {
    if (preg_match('/ja4=(t1[23]d[0-9]{4}h1_[a-f0-9]{12}_[a-f0-9]{12}) raw=(\S+)/', $line, $parts) !== 1) {
        continue;
    }
    $raw = explode('_', $parts[2], 3);
    if (count($raw) !== 3 || $raw[1] === '' || $raw[2] === '') {
        throw new RuntimeException('Missing captured TLS components.');
    }
    $calculated = $raw[0] . '_' . substr(hash('sha256', $raw[1]), 0, 12)
        . '_' . substr(hash('sha256', $raw[2]), 0, 12);
    if ($calculated !== $parts[1]) {
        throw new RuntimeException('JA4 hash reconciliation failed.');
    }
    $matched[] = $calculated;
}
if ($matched !== $observed) {
    throw new RuntimeException('Proxy and application fingerprints differ.');
}
echo "Measured JA4 and raw TLS hashes reconciled.\n";
