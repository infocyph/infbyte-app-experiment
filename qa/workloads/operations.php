<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Database\DBLayerFactory;

require __DIR__ . '/bootstrap.php';

$app = application();
$app->execution()->run(static function () use ($app, $argv): void {
    $db = $app->make(DBLayerFactory::class)->connection();
    switch ($argv[1] ?? '') {
        case 'schema':
            $db->statement('CREATE TABLE experiment_reports (id VARCHAR(80) PRIMARY KEY, amount INTEGER NOT NULL)');
            break;
        case 'verify':
            ensure(countValue($db->scalar('SELECT COUNT(*) FROM experiment_reports')) === 1, 'Concurrent scheduler wrote multiple or no reports.');
            ensure(countValue($db->scalar('SELECT SUM(amount) FROM experiment_reports')) === 30000, 'Report does not reconcile to orders.');
            break;
        case 'cache-fail':
            try {
                $app->make(CacheManager::class)->store('shared')->set('outage-test', 'must-not-succeed', 30);
            } catch (\Throwable $failure) {
                ensure($failure instanceof \Infocyph\CacheLayer\Exceptions\CacheBackendException
                    || $failure instanceof \RedisException, 'Unexpected cache outage failure type: ' . $failure::class);
                return;
            }
            throw new \RuntimeException('Cache reported success despite unavailable authoritative backend.');
        case 'cache-recover':
            $cache = $app->make(CacheManager::class)->store('shared');
            ensure($cache->set('recovered', 'healthy', 30) && $cache->get('recovered') === 'healthy', 'Redis did not recover.');
            break;
        default:
            throw new \InvalidArgumentException('Unknown operations probe.');
    }
});
