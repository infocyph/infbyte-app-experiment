<?php

declare(strict_types=1);

namespace Experiment;

require __DIR__ . '/bootstrap.php';

$root = getenv('PROJECT');
ensure(is_string($root), 'Missing project root.');
$config = configuration();
foreach ($config as $key => $value) {
    if (!is_array($value) || $key === 'modules') {
        continue;
    }
    $path = $root . '/config/' . $key . '.php';
    $base = is_file($path) ? require $path : [];
    ensure(is_array($base), 'Invalid existing configuration.');
    $merged = array_replace_recursive($base, $value);
    ensure(file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($merged, true) . ";\n", LOCK_EX) !== false, 'Config fixture write failed.');
    chmod($path, 0600);
}
if (getenv('SCENARIO') === 'oauth-http') {
    return; // Foundation owns the protocol routes; never register duplicate adapters.
}
if (getenv('SCENARIO') === 'operations') {
    $console = $root . '/routes/console.php';
    ensure(file_put_contents($console, "<?php\n\ndeclare(strict_types=1);\n\nreturn ['lab:report' => \\Experiment\\ReportCommand::class];\n", LOCK_EX) !== false, 'Command registration failed.');
    ensure(copy(__DIR__ . '/schedule-routes.php', $root . '/routes/schedule.php'), 'Schedule registration failed.');
    return;
}
$route = $root . '/routes/api.php';
$original = file_get_contents($route);
$addition = file_get_contents(__DIR__ . match (getenv('SCENARIO')) {
    'ecosystem-release-cutover' => '/release-routes.php',
    'ecosystem-tenants' => '/tenant-routes.php',
    'browser' => '/browser-routes.php',
    'transfer' => '/transfer-routes.php',
    'ecosystem-connected', 'ecosystem-recovery' => '/connected-routes.php',
    'ecosystem-endurance' => '/endurance-routes.php',
    default => '/http-routes.php',
});
ensure(is_string($original) && is_string($addition), 'Missing route source.');
$addition = substr($addition, strpos($addition, 'use Experiment'));
ensure(file_put_contents($route, rtrim($original) . "\n\n" . $addition, LOCK_EX) !== false, 'Route registration failed.');
