<?php

declare(strict_types=1);

use Experiment\ConnectedOrders;

/** @var \Infocyph\Webrick\Router\Definition\Registrar $router */
$router->post('/api/connected/{id:slug}/{quantity:int}', [ConnectedOrders::class, 'create'], ['as' => 'lab.connected.create']);
