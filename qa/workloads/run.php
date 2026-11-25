<?php

declare(strict_types=1);

namespace Experiment;

require __DIR__ . '/bootstrap.php';

$app = application();
$scenario = match (getenv('SCENARIO')) {
    'orders' => new Orders($app),
    'accounts' => new Accounts($app),
    'mail' => new Mail($app),
    'security', 'passkey', 'authorization' => new Security($app),
    'http', 'operations' => new Orders($app),
    'queue' => new Queues($app),
    'protocols' => new Protocols($app),
    default => throw new \InvalidArgumentException('Unknown workload.'),
};
$mode = $argv[1] ?? '';
if ($mode === 'setup') {
    $app->execution()->run($scenario->setup(...));
    if (getenv('SCENARIO') === 'http') {
        $app->execution()->run(static fn() => HttpOrders::seed($app));
    }
} elseif ($mode === 'verify') {
    $app->execution()->run(static fn() => $scenario->verify((int) ($argv[2] ?? 0)));
} elseif ($mode === 'crash-seed' && $scenario instanceof Queues) {
    $app->execution()->run($scenario->seedCrash(...));
} elseif ($mode === 'crash-reserve' && $scenario instanceof Queues) {
    $scenario->reserveCrash();
} elseif ($mode === 'crash-verify' && $scenario instanceof Queues) {
    $app->execution()->run($scenario->verifyCrash(...));
} elseif ($mode === 'drain' && $scenario instanceof Queues) {
    $scenario->drain();
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
