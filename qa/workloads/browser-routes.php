<?php

declare(strict_types=1);

use Experiment\Browser;
use Infocyph\Webrick\Middleware\CacheValidatorsMiddleware;
use Infocyph\Webrick\Middleware\CompressionMiddleware;

/** @var \Infocyph\Webrick\Router\Definition\Registrar $router */
$router->get('/lab/session', Browser::state(...), ['middleware' => ['session', 'csrf']]);
$router->post('/lab/session/add', Browser::increment(...), ['middleware' => ['session', 'csrf']]);
$router->post('/lab/session/rotate', Browser::rotate(...), ['middleware' => ['session', 'csrf']]);
$router->post('/lab/session/invalidate', Browser::invalidate(...), ['middleware' => ['session', 'csrf']]);
$router->post('/lab/session/flash', Browser::flash(...), ['middleware' => ['session', 'csrf']]);
$router->get('/lab/items/new', Browser::literal(...), 'lab.items.new');
$router->get('/lab/items/{id:int}', Browser::item(...), 'lab.items.show');
$router->get('/lab/negotiate', Browser::negotiate(...));
$router->get('/lab/compress', Browser::compress(...), ['middleware' => [CacheValidatorsMiddleware::class, CompressionMiddleware::class]]);
$router->get('/lab/assets/{name}', Browser::asset(...));
$router->get('/lab/stream', Browser::stream(...));
