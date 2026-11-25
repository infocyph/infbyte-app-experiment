<?php

declare(strict_types=1);

namespace Experiment;

final readonly class Receipt
{
    public function __construct(public string $id, public string $kind) {}
}
