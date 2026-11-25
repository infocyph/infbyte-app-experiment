<?php

declare(strict_types=1);

namespace Experiment;

/** @throws \RuntimeException */
function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

/** @throws \RuntimeException */
function countValue(mixed $value): int
{
    if (!is_numeric($value)) {
        throw new \RuntimeException('Expected numeric database aggregate.');
    }

    return (int) $value;
}
