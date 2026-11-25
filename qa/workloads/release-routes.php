<?php

declare(strict_types=1);

use Experiment\ReleaseProbe;

$router->get('/api/lab/release', [ReleaseProbe::class, 'old']);
