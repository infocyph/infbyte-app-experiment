<?php

declare(strict_types=1);

namespace Experiment;

require __DIR__ . '/bootstrap.php';

$app = application();
$scenario = new Endurance($app);
$app->execution()->run(match ($argv[1] ?? '') {
    'setup' => $scenario->setup(...),
    'await-commit' => $scenario->awaitCommitted(...),
    'reconcile' => $scenario->reconcile(...),
    default => throw new \InvalidArgumentException('Invalid endurance operation.'),
});
