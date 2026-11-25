<?php

declare(strict_types=1);

namespace Experiment;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/Orders.php';
require __DIR__ . '/Accounts.php';
require __DIR__ . '/Mail.php';

$app = application();
$scenario = match (getenv('SCENARIO')) {
    'orders' => new Orders($app),
    'accounts' => new Accounts($app),
    'mail' => new Mail($app),
    default => throw new \InvalidArgumentException('Unknown workload.'),
};
$mode = $argv[1] ?? '';
if ($mode === 'setup') {
    $app->execution()->run($scenario->setup(...));
} elseif ($mode === 'verify') {
    $app->execution()->run(static fn() => $scenario->verify((int) ($argv[2] ?? 0)));
} elseif ($mode === 'worker') {
    $worker = $argv[2] ?? '';
    $iterations = (int) ($argv[3] ?? 0);
    ensure(preg_match('/^[0-9]+-[0-9]+$/D', $worker) === 1 && $iterations > 0, 'Invalid worker arguments.');
    for ($i = 0; $i < $iterations; ++$i) {
        $start = hrtime(true);
        $app->execution()->run(static fn() => $scenario->execute($worker . '-' . $i));
        echo json_encode(['id' => $worker . '-' . $i, 'milliseconds' => (hrtime(true) - $start) / 1e6], JSON_THROW_ON_ERROR), PHP_EOL;
    }
} else {
    throw new \InvalidArgumentException('Unknown workload mode.');
}
