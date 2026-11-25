<?php

declare(strict_types=1);

namespace Experiment;

require __DIR__ . '/bootstrap.php';
$app = application();
$orders = new TenantOrders($app);
match ($argv[1] ?? '') {
    'setup' => $app->execution()->run($orders->setup(...)),
    'verify' => $app->execution()->run(static fn() => $orders->verify((int) $argv[2])),
    default => throw new \InvalidArgumentException('Unknown tenant fixture mode.'),
};
