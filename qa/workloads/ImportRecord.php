<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\ArrayKit\DTO\Concerns\DTOTrait;

final class ImportRecord
{
    use DTOTrait;

    public string $email = '';
    public array $items = [];
}
