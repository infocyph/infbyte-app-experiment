<?php

declare(strict_types=1);

require getenv('PROJECT') . '/vendor/autoload.php';

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param class-string<Throwable> $type */
function expectFailure(callable $operation, string $type, string $message): void
{
    try {
        $operation();
    } catch (Throwable $failure) {
        if ($failure instanceof $type) {
            return;
        }

        throw $failure;
    }

    throw new RuntimeException($message);
}

function evidenceDirectory(): string
{
    $path = getenv('EVIDENCE');
    verify(is_string($path) && is_dir($path), 'Evidence directory unavailable.');

    return $path;
}

require __DIR__ . '/ecosystem-data.php';

$start = hrtime(true);
match ($argv[1] ?? '') {
    'intermix' => intermixWorkload(),
    'array-config' => arrayConfigWorkload(),
    'array-stream' => arrayStreamWorkload(),
    'uid-formats' => uidFormatsWorkload(),
    default => throw new InvalidArgumentException('Unknown ecosystem workload.'),
};
echo json_encode(['scenario' => $argv[1], 'milliseconds' => (hrtime(true) - $start) / 1e6, 'peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR), PHP_EOL;
