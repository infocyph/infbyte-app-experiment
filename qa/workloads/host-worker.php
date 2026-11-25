<?php

declare(strict_types=1);

use Infocyph\Foundation\Release\FoundationReleaseBootstrap;
use Infocyph\Runwire\FrankenPhpOptions;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\FrankenPhpMode;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;

$project = dirname(__DIR__);
require $project . '/vendor/autoload.php';

$release = FoundationReleaseBootstrap::fromEnvironment(['base_path' => $project])
    ?? throw new RuntimeException('Trusted compiled release is required.');
$driver = function_exists('frankenphp_handle_request')
    ? RuntimeDriver::FRANKENPHP
    : match ($argv[1] ?? '') {
        'roadrunner' => RuntimeDriver::ROADRUNNER,
        default => throw new RuntimeException('Actual hosted runtime is required.'),
    };

Runtime::create(new RuntimeOptions(
    driver: $driver,
    frankenPhp: new FrankenPhpOptions(mode: FrankenPhpMode::WORKER),
))->serveApplication($release);
