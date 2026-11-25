<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Metrics\InMemoryCacheMetricsCollector;

function cacheLockOwnershipWorkload(string $backend): void
{
    [$cache, $peer] = cacheContractPeers($backend, 'lock-ownership');
    $owner = $cache->authenticationStateLock();
    $contender = $peer->authenticationStateLock();
    verify($owner !== null && $contender !== null, 'Documented lock capability absent.');
    foreach ([0.0, -1.0, INF, NAN] as $invalid) {
        expectFailure(static fn() => $owner->acquire('invalid', 0.0, $invalid), InvalidArgumentException::class, 'Invalid lease accepted.');
    }
    verify(!$owner->refresh(null, 1.0), 'Missing handle renewed.');
    $owner->release(null);
    $first = $owner->acquire('report', 0.0, 20.0);
    verify($first instanceof LockHandle, 'Initial ownership unavailable.');
    $second = null;
    try {
        verify($contender->acquire('report', 0.15, 20.0) === null, 'Concurrent ownership granted.');
        verify($owner->refresh($first, 20.0), 'Active owner could not renew.');
        $independent = $contender->acquire('other-report', 0.0, 20.0);
        verify($independent instanceof LockHandle, 'Different key blocked.');
        $contender->release($independent);
        $owner->release($first);
        $second = $contender->acquire('report', 4.0, 20.0);
        verify($second instanceof LockHandle && $second->token !== $first->token, 'Replacement owner unavailable.');
        verify(!$owner->refresh($first, 20.0), 'Released owner renewed replacement.');
        $owner->release($first);
        verify($contender->refresh($second, 20.0) && $owner->acquire('report', 0.0, 20.0) === null, 'Stale release displaced replacement.');
    } finally {
        $owner->release($first);
        $contender->release($second);
    }

    // Native advisory locks and flock are connection/resource scoped, not timed leases.
    $timed = in_array($backend, ['redis', 'valkey', 'memcached'], true);
    $first = $owner->acquire('lease', 4.0, 1.0);
    verify($first instanceof LockHandle, 'Short lease unavailable.');
    $second = null;
    try {
        if ($timed) {
            $second = $contender->acquire('lease', 5.0, 20.0);
            verify($second instanceof LockHandle && $second->token !== $first->token, 'Timed lease did not expire.');
            verify(!$owner->refresh($first, 20.0), 'Expired owner renewed successor.');
            $owner->release($first);
            verify($contender->refresh($second, 20.0), 'Expired owner removed successor.');
        } else {
            usleep(1_200_000);
            verify($contender->acquire('lease', 0.0, 20.0) === null && $owner->refresh($first, 20.0), 'Resource-scoped lock incorrectly expired.');
        }
    } finally {
        $owner->release($first);
        $contender->release($second);
    }
    echo "Shared $backend lock ownership, renewal and provider lease semantics passed\n";
}

function cacheStampedeWorkload(string $backend): void
{
    $root = evidenceDirectory() . '/cache-stampede';
    verify(mkdir($root, 0700), 'Stampede fixture directory unavailable.');
    $namespace = 'contract-stampede';
    $marker = $root . '/computations';
    $results = parallelRace(static function (int $worker) use ($backend, $namespace, $root, $marker): int {
        $cache = sharedAtomicCache($backend, $namespace, $root);
        return $cache->remember('invoice', static function () use ($marker, $worker): int {
            verify(file_put_contents($marker, "$worker\n", FILE_APPEND | LOCK_EX) !== false, 'Computation evidence unavailable.');
            usleep(150_000);
            return 42;
        }, 600);
    }, 'cache-stampede', 12);
    verify(count($results) === 12 && array_sum($results) === 504 && count(array_unique($results, SORT_REGULAR)) === 1, 'Concurrent callers disagreed.');
    $computations = file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    verify(is_array($computations) && count($computations) === 1, 'Cold key computed more than once.');
    $peer = sharedAtomicCache($backend, $namespace, $root);
    verify($peer->get('invoice') === 42, 'Independent peer did not observe publication.');
    verify($peer->remember('invoice', static fn() => throw new LogicException('Warm key recomputed.'), 600) === 42, 'Warm result changed.');
    $foreign = sharedAtomicCache($backend, 'foreign-stampede', $root);
    verify($foreign->remember('invoice', static fn(): int => 99, 600) === 99 && $peer->get('invoice') === 42, 'Namespace publication crossed boundary.');
    echo "Shared $backend 12-process cold-key stampede and namespace isolation passed\n";
}

function cacheMetricsWorkload(string $backend): void
{
    [$cache, $peer] = cacheContractPeers($backend, 'metrics');
    $cache->setMetricsCollector(new InMemoryCacheMetricsCollector());
    $exports = [];
    $cache->setMetricsExportHook(static function (array $snapshot) use (&$exports): void {
        $exports[] = $snapshot;
    });
    verify($cache->set('invoice', false, 600), 'Metric fixture write failed.');
    verify($cache->get('invoice') === false && $cache->get('missing', 'absent') === 'absent', 'Metric fixture read failed.');
    verify($cache->remember('projection', static fn(): int => 42, 600) === 42, 'Metric fixture resolver failed.');
    verify($cache->remember('projection', static fn() => throw new LogicException('Warm key recomputed.'), 600) === 42, 'Warm metric fixture changed.');
    verify($cache->delete('invoice') && !$peer->has('invoice'), 'Metric fixture deletion failed.');
    $snapshot = $cache->exportMetrics();
    $totals = [];
    foreach ($snapshot as $counters) {
        foreach ($counters as $name => $count) {
            $totals[$name] = ($totals[$name] ?? 0) + $count;
        }
    }
    foreach (['set' => 1, 'get_hit' => 1, 'get_miss' => 1, 'remember_hit' => 1, 'remember_miss' => 1, 'lock_acquired' => 1, 'delete' => 1] as $name => $minimum) {
        verify(($totals[$name] ?? 0) >= $minimum, 'Expected metric missing: ' . $name);
    }
    verify(($totals['lock_timeout'] ?? 0) === 0 && ($totals['backend_failure'] ?? 0) === 0, 'Healthy operations emitted failure metrics.');
    verify($exports === [$snapshot], 'Export hook did not receive exact snapshot.');
    $cache->setMetricsExportHook(null);
    verify($cache->exportMetrics() === $snapshot && $exports === [$snapshot], 'Removing hook changed snapshot or invoked old hook.');
    $cache->setMetricsCollector(new InMemoryCacheMetricsCollector());
    verify($cache->exportMetrics() === [] && $peer->get('projection') === 42, 'Collector replacement changed stored values or retained old counters.');
    echo "Shared $backend metrics, export hook and collector lifecycle passed\n";
}
