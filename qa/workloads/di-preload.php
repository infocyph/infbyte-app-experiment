<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;

$root = getenv('EVIDENCE');
$fixture = $root . '/preload-service.php';
$artifact = $root . '/di-preload.php';
if (($argv[1] ?? '') === 'generate') {
    require getenv('PROJECT') . '/vendor/autoload.php';
    if (file_put_contents($fixture, '<?php final class LabPreloadedService { public function value(): int { return 42; } }', LOCK_EX) === false) {
        throw new RuntimeException('Preload fixture write failed.');
    }
    require $fixture;
    ContainerBuilder::create('preload-startup')
        ->autowire(LabPreloadedService::class, LabPreloadedService::class)
        ->generatePreload($artifact);
    if (!str_contains((string) file_get_contents($artifact), $fixture)) {
        throw new RuntimeException('Generated artifact omitted registered class file.');
    }
    exit;
}
if (($argv[1] ?? '') !== 'verify' || !class_exists(LabPreloadedService::class, false)) {
    throw new RuntimeException('Service was not loaded at PHP startup.');
}
$status = opcache_get_status(false);
if (!is_array($status) || !in_array(LabPreloadedService::class, $status['preload_statistics']['classes'] ?? [], true)) {
    throw new RuntimeException('OPcache did not report the preloaded class.');
}
require getenv('PROJECT') . '/vendor/autoload.php';
$runtime = ContainerBuilder::create('preload-startup')
    ->autowire(LabPreloadedService::class, LabPreloadedService::class)->build();
$service = $runtime->get(LabPreloadedService::class);
if ($service->value() !== 42 || $service !== $runtime->get(LabPreloadedService::class)) {
    throw new RuntimeException('Preloaded service resolution changed.');
}
echo "Generated artifact executed at startup; OPcache class and singleton identity verified\n";
