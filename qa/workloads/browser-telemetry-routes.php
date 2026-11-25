<?php

declare(strict_types=1);

use Experiment\LabWebTelemetry;

/** @var \Infocyph\Webrick\Router\Definition\Registrar $router */
$router->get('/lab/telemetry/trace/{id}', LabWebTelemetry::trace(...), ['middleware' => [LabWebTelemetry::observe(...)]]);
$router->get('/lab/telemetry/unavailable/{id}', LabWebTelemetry::unavailable(...), ['middleware' => [LabWebTelemetry::observe(...)]]);
$router->get('/lab/telemetry/failure/{id}', LabWebTelemetry::failure(...));
