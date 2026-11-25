<?php

declare(strict_types=1);

use Experiment\RiskEdge;

$router->get('/api/lab/ordinary', [RiskEdge::class, 'ordinary'], ['as' => 'lab.edge.ordinary']);
$router->post('/api/lab/login', [RiskEdge::class, 'login'], ['as' => 'lab.edge.login']);
