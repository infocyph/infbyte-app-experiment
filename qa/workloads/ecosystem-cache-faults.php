<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cluster\Transport\RedisStreamInvalidationTransport;
use Infocyph\CacheLayer\Exceptions\CacheBackendException;

function cacheOutageWorkload(): void
{
    $root = (string) getenv('LAB_CACHE_FAULT_ROOT');
    $dsn = 'redis://127.0.0.1:17389';
    $closed = Cache::redis('outage', $dsn, options: new CacheOptions(failOpen: false));
    $open = Cache::redis('outage', $dsn, options: new CacheOptions(failOpen: true));
    verify($closed->set('durable', ['value' => 42], 600), 'Seed failed.');
    verify($open->get('durable') === ['value' => 42], 'Initial cache state mismatch.');
    verify(touch($root . '/seeded'), 'Could not publish readiness.');
    cacheWaitMarker($root . '/stopped');
    expectFailure(static fn() => $closed->get('durable'), CacheBackendException::class, 'Fail-closed read concealed outage.');
    expectFailure(static fn() => $closed->set('lost', 1, 600), CacheBackendException::class, 'Fail-closed write concealed outage.');
    verify($open->get('durable', 'fallback') === 'fallback', 'Fail-open read failed to return default.');
    verify(!$open->set('lost', 1, 600), 'Fail-open write reported success during outage.');
    verify(touch($root . '/observed'), 'Could not publish outage evidence.');
    cacheWaitMarker($root . '/restarted');
    $replacement = Cache::redis('outage', $dsn, options: new CacheOptions(failOpen: false));
    verify($replacement->get('durable') === ['value' => 42] && !$replacement->has('lost'), 'Restart lost durable value or published failed write.');
    verify($replacement->set('recovered', 2, 600) && $replacement->get('recovered') === 2, 'Replacement write failed.');
    echo "Actual Redis stop, fail-open/closed and durable restart reconciliation passed\n";
}

function cacheWaitMarker(string $path): void
{
    $deadline = hrtime(true) + 60_000_000_000;
    while (!is_file($path)) {
        verify(hrtime(true) < $deadline, 'Cache fixture barrier timed out.');
        usleep(20000);
    }
}

function invalidationProcessesWorkload(): void
{
    $connect = static function (): RedisStreamInvalidationTransport {
        $redis = new Redis();
        verify($redis->connect('127.0.0.1', 6379, 2.0), 'Redis unavailable.');

        return new RedisStreamInvalidationTransport($redis, prefix: 'lab:process-events:', maxLength: 10000);
    };
    $root = evidenceDirectory() . '/process-barriers';
    verify(mkdir($root, 0700), 'Barrier directory unavailable.');
    $results = parallelRace(static function (int $worker) use ($root, $connect): int {
        $node = invalidationNode($connect(), 'process-' . $worker);
        for ($id = 0; $id < 80; ++$id) {
            verify($node->cache()->set('item-' . $id, 'stale', 600), 'Node seed failed.');
        }
        verify($node->cache()->remember('tagged', static fn(): string => 'stale', 600, ['catalog']) === 'stale', 'Tagged seed failed.');
        verify($node->cache()->set('unrelated', 'keep', 600), 'Unrelated seed failed.');
        verify(touch($root . '/ready-' . $worker), 'Worker ready marker failed.');
        for ($peer = 0; $peer < 8; ++$peer) {
            cacheWaitMarker($root . '/ready-' . $peer);
        }
        if ($worker === 0) {
            for ($id = 0; $id < 80; ++$id) {
                $node->invalidateKey('item-' . $id);
            }
            $node->invalidateTag('catalog');
            verify(touch($root . '/published'), 'Publication marker failed.');
        } else {
            cacheWaitMarker($root . '/published');
        }
        $node->drain(16, 20);
        for ($id = 0; $id < 80; ++$id) {
            verify(!$node->cache()->has('item-' . $id), 'Independent node retained stale entry.');
        }
        verify(!$node->cache()->has('tagged') && $node->cache()->get('unrelated') === 'keep', 'Tag invalidation or unrelated key boundary failed.');
        $cursor = $node->status()->cursor;
        unset($node);
        $replacement = invalidationNode($connect(), 'process-' . $worker);
        verify($replacement->status()->cursor === $cursor && !$replacement->cache()->has('item-0'), 'Replacement lost durable cursor/coherence.');

        return 81;
    }, 'invalidation-processes', 8);
    verify(array_sum($results) === 648, 'Node reconciliation count mismatch.');
    echo "Eight independent process-owned caches, 81 invalidations and durable cursor replacement passed\n";
}
