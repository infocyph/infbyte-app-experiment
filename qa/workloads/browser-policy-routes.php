<?php

declare(strict_types=1);

use Experiment\Browser;
use Infocyph\Webrick\Middleware\CacheValidatorsMiddleware;
use Infocyph\Webrick\Middleware\CompressionMiddleware;
use Infocyph\Webrick\Middleware\CorsMiddleware;
use Infocyph\Webrick\Middleware\NegotiationMiddleware;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;

/** @var \Infocyph\Webrick\Router\Definition\Registrar $router */
$cors = new Cors(origins: ['https://client.example.test'], methods: 'GET, HEAD', headers: ['X-Lab-Request'],
    exposeHeaders: ['ETag'], maxAgeSeconds: 120, allowCredentials: true);
$router->get('/lab/policy/report', Browser::policyReport(...), [
    'middleware' => [CorsMiddleware::class, NegotiationMiddleware::class, CacheValidatorsMiddleware::class, CompressionMiddleware::class],
    'attributes' => ['cors' => $cors, 'produces' => new Produces(['text/plain'], ['utf-8'])],
]);
$router->options('/lab/policy/report', Browser::policyPreflight(...), [
    'middleware' => [CorsMiddleware::class], 'attributes' => ['cors' => $cors],
]);
$router->get('/lab/policy/plain', Browser::policyReport(...));
$router->get('/lab/policy/assets/{name}', Browser::asset(...), ['middleware' => [CompressionMiddleware::class]]);
$router->get('/lab/policy/stream', Browser::stream(...), ['middleware' => [CompressionMiddleware::class]]);
