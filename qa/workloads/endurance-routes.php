<?php

declare(strict_types=1);

use Experiment\Endurance;

/** @var \Infocyph\Webrick\Router\Definition\Registrar $router */
$router->post('/api/endurance/{tenant:int}/{id:slug}', [Endurance::class, 'create']);
$router->get('/api/endurance/{tenant:int}/{id:slug}', [Endurance::class, 'read']);
