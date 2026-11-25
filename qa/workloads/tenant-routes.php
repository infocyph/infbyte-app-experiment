<?php

declare(strict_types=1);

use Experiment\TenantOrders;

$router->post('/api/tenant/{tenant:slug}/checkout/{request:slug}', [TenantOrders::class, 'checkout'], ['as' => 'tenant.checkout', 'middleware' => ['resolve-auth', 'auth']]);
$router->get('/api/tenant/{tenant:slug}/orders/{id:slug}', [TenantOrders::class, 'read'], ['as' => 'tenant.read', 'middleware' => ['resolve-auth', 'auth']]);
