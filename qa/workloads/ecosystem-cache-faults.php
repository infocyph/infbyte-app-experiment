<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cluster\Transport\RedisStreamInvalidationTransport;
use Infocyph\CacheLayer\Exceptions\CacheBackendException;

function cacheOutageWorkload(string $backend = 'redis'): void
{
    $root = (string) getenv('LAB_CACHE_FAULT_ROOT');
    $make = static fn(bool $failOpen): Cache => match ($backend) {
        'redis' => Cache::redis('outage', 'redis://127.0.0.1:17389', options: new CacheOptions(failOpen: $failOpen)),
        'valkey' => Cache::valkey('outage', 'valkey://127.0.0.1:17389', options: new CacheOptions(failOpen: $failOpen)),
        'memcached' => Cache::memcached('outage', [['127.0.0.1', 17389, 0]], options: new CacheOptions(failOpen: $failOpen)),
        default => throw new InvalidArgumentException('Unknown cache outage backend.'),
    };
    $closed = $make(false);
    $open = $make(true);
    verify($closed->set('durable', ['value' => 42], 600), 'Seed failed.');
    verify($open->get('durable') === ['value' => 42], 'Initial cache state mismatch.');
    $closedAtomic = $closed->atomic();
    $openAtomic = $open->atomic();
    verify($closedAtomic !== null && $openAtomic !== null && $closedAtomic->setIfAbsent('lease', 'owned', 600), 'Atomic outage seed failed.');
    verify(touch($root . '/seeded'), 'Could not publish readiness.');
    cacheWaitMarker($root . '/stopped');
    expectFailure(static fn() => $closed->get('durable'), CacheBackendException::class, 'Fail-closed read concealed outage.');
    expectFailure(static fn() => $closed->set('lost', 1, 600), CacheBackendException::class, 'Fail-closed write concealed outage.');
    verify($open->get('durable', 'fallback') === 'fallback', 'Fail-open read failed to return default.');
    verify(!$open->set('lost', 1, 600), 'Fail-open write reported success during outage.');
    expectFailure(static fn() => $closedAtomic->compareAndSet('lease', 'owned', 'changed', 600), CacheBackendException::class, 'Fail-closed CAS concealed outage.');
    verify(!$openAtomic->setIfAbsent('lost-atomic', 'new', 600) && $openAtomic->getAndDelete('lease', 'fallback') === 'fallback', 'Fail-open atomic result misrepresented outage.');
    verify(touch($root . '/observed'), 'Could not publish outage evidence.');
    cacheWaitMarker($root . '/restarted');
    $replacement = $make(false);
    $expected = $backend === 'memcached' ? null : ['value' => 42];
    verify($replacement->get('durable') === $expected && !$replacement->has('lost') && !$replacement->has('lost-atomic'), 'Restart durability or rejected mutation contract failed.');
    verify($replacement->get('lease') === ($backend === 'memcached' ? null : 'owned'), 'Outage modified a previously confirmed atomic value.');
    verify($replacement->set('recovered', 2, 600) && $replacement->get('recovered') === 2, 'Replacement write failed.');
    echo "Actual $backend stop, fail-open/closed, atomic failure and restart reconciliation passed\n";
}

function cacheWaitMarker(string $path, int $seconds = 60): void
{
    $deadline = hrtime(true) + $seconds * 1_000_000_000;
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

function remoteCacheOutageContracts(string $backend, bool $failOpen): void
{
    $root = (string) getenv('LAB_CACHE_FAULT_ROOT');
    verify(is_dir($root), 'Missing outage fixture barrier.');
    $namespace = 'remote-outage';
    $make = static fn(string $name, bool $open): Cache => sharedAtomicCache($backend, $name, $root, new CacheOptions(failOpen: $open));
    $active = $make($namespace, $failOpen);
    $observer = $make($namespace, false);
    $foreign = $make('foreign-outage', false);
    verify($active->set('confirmed', ['value' => 42], 1800), 'Confirmed seed failed.');
    verify($observer->get('confirmed') === ['value' => 42], 'Independent seed read failed.');
    verify($foreign->set('confirmed', 'foreign', 1800), 'Foreign namespace seed failed.');
    $atomic = $active->atomic();
    verify(($atomic !== null) === ($backend === 'mongodb'), 'Backend atomic capability changed.');
    if ($atomic !== null) {
        verify($atomic->setIfAbsent('lease', 'owned', 1800), 'Atomic seed failed.');
    }
    verify(touch($root . '/seeded'), 'Outage seed marker failed.');
    cacheWaitMarker($root . '/stopped');
    if ($failOpen) {
        verify($active->get('confirmed', 'unavailable') === 'unavailable', 'Fail-open read concealed outage or returned stale data.');
        verify(!$active->set('rejected', 1, 1800), 'Fail-open write reported success.');
        if ($atomic !== null) {
            verify(!$atomic->compareAndSet('lease', 'owned', 'changed', 1800), 'Fail-open CAS reported success.');
        }
    } else {
        expectFailure(static fn() => $active->get('confirmed'), CacheBackendException::class, 'Fail-closed read concealed outage.');
        expectFailure(static fn() => $active->set('rejected', 1, 1800), CacheBackendException::class, 'Fail-closed write concealed outage.');
        if ($atomic !== null) {
            expectFailure(static fn() => $atomic->compareAndSet('lease', 'owned', 'changed', 1800), CacheBackendException::class, 'Fail-closed CAS concealed outage.');
        }
    }
    verify(touch($root . '/observed'), 'Outage observation marker failed.');
    cacheWaitMarker($root . '/restarted', 240);
    $replacement = $make($namespace, false);
    $other = $make('foreign-outage', false);
    verify($replacement->get('confirmed') === ['value' => 42] && !$replacement->has('rejected'), 'Confirmed/rejected mutation reconciliation failed.');
    verify($other->get('confirmed') === 'foreign', 'Outage crossed namespace.');
    if ($atomic !== null) {
        verify($replacement->get('lease') === 'owned', 'Failed CAS modified confirmed lease.');
        $replacementAtomic = $replacement->atomic();
        verify($replacementAtomic !== null && $replacementAtomic->compareAndSet('lease', 'owned', 'recovered', 600), 'Replacement CAS failed.');
    }
    verify($replacement->set('recovered', 2, 600), 'Replacement write failed.');
    verify($make($namespace, false)->get('recovered') === 2, 'Fresh independent recovery read failed.');
    echo "Actual $backend outage, fail policy, confirmed-state reconciliation and fresh recovery passed\n";
}
