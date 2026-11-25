<?php

declare(strict_types=1);

namespace Experiment;

require __DIR__ . '/bootstrap.php';

$app = application();
$app->execution()->run(static fn() => RiskEdge::seed($app));
