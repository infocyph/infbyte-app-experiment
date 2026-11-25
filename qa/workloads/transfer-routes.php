<?php

declare(strict_types=1);

use Experiment\Transfer;

/** @var \Infocyph\Webrick\Router\Definition\Registrar $router */
$router->post('/lab/transfer', Transfer::upload(...));
$router->get('/lab/events', Transfer::events(...));
