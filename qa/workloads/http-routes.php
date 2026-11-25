<?php

declare(strict_types=1);

use Experiment\HttpOrders;
use Infocyph\Webrick\Router\Definition\Registrar;

/** @var Registrar $router */
$router->post('/api/lab/orders/{id:slug}', [HttpOrders::class, 'create'], ['as' => 'lab.orders.create', 'middleware' => ['resolve-auth', 'auth']]);
$router->get('/api/lab/orders/{id:slug}', [HttpOrders::class, 'read'], ['as' => 'lab.orders.read', 'middleware' => ['resolve-auth', 'auth']]);
