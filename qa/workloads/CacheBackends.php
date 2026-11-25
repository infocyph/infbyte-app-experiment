<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;

final readonly class CacheBackends
{
    private string $root;
    private CacheOptions $options;

    public function __construct(private string $backend)
    {
        $root = getenv('PROJECT');
        $key = getenv('AUTH_TOKEN_SECRET');
        ensure(is_string($root) && is_string($key) && strlen($key) >= 32, 'Missing isolated cache configuration.');
        $this->root = $root . '/storage/lab-cache';
        $this->options = new CacheOptions(integrityKey: $key, failOpen: false);
    }

    private function cache(string $namespace = 'lab-orders'): Cache
    {
        return match ($this->backend) {
            'cache-file' => Cache::file($namespace, $this->root . '/files', $this->options),
            'cache-phpfiles' => Cache::phpFiles($namespace, $this->root . '/phpfiles', $this->options),
            'cache-sqlite' => Cache::sqlite($namespace, $this->root . '/cache.sqlite', $this->options),
            'cache-mysql' => Cache::pdo($namespace, 'mysql:host=127.0.0.1;port=3306;dbname=experiment', 'experiment', 'experiment', options: $this->options),
            'cache-pgsql' => Cache::pdo($namespace, 'pgsql:host=127.0.0.1;port=5432;dbname=experiment', 'experiment', 'experiment', options: $this->options),
            'cache-valkey' => Cache::valkey($namespace, 'valkey://127.0.0.1:6380', options: $this->options),
            'cache-memcached' => Cache::memcached($namespace, options: $this->options),
        };
    }

    public function setup(): void
    {
        foreach (['', '/files', '/phpfiles'] as $suffix) {
            $path = $this->root . $suffix;
            if (!is_dir($path)) {
                ensure(mkdir($path, 0700, true), 'Cache fixture directory unavailable.');
            }
        }
        ensure($this->cache()->clear() && $this->cache('lab-foreign')->clear(), 'Isolated cache reset failed.');
    }

    public function execute(string $id): void
    {
        $cache = $this->cache();
        $foreign = $this->cache('lab-foreign');
        $key = 'order.' . $id;
        $payload = ['id' => $id, 'cents' => 1599, 'currency' => 'USD'];
        ensure($cache->isAuthoritative() && !$cache->isFailOpen() && $cache->hasPayloadIntegrity(), 'Cache policy mismatch.');
        ensure($cache->setMultiple([$key => $payload, $key . '.count' => 2], 3600), 'Bulk cache save failed.');
        $values = iterator_to_array($cache->getMultiple([$key, $key . '.count', $key . '.missing'], 'absent'));
        ensure($values === [$key => $payload, $key . '.count' => 2, $key . '.missing' => 'absent'], 'Bulk cache read mismatch.');
        ensure($foreign->get($key, 'absent') === 'absent', 'Namespace data leaked.');
        ensure($foreign->set($key, 'foreign', 3600), 'Foreign namespace save failed.');
        ensure($cache->get($key) === $payload, 'Foreign namespace overwrite affected order.');
        $item = $cache->getItem($key . '.deferred');
        $item->set($id)->expiresAfter(3600);
        ensure($cache->saveDeferred($item) && $cache->commit(), 'Deferred PSR-6 save failed.');
        ensure($cache->get($key . '.deferred') === $id, 'Deferred cache value missing.');
        $tag = 'tag.' . $id;
        ensure($cache->remember($key . '.summary', static fn() => $payload, 3600, [$tag]) === $payload, 'Tagged cache load failed.');
        ensure($cache->invalidateTag($tag), 'Tag invalidation failed.');
        ensure(!$cache->has($key . '.summary') && $cache->get($key) === $payload, 'Tag invalidation scope mismatch.');
        ensure($cache->deleteMultiple([$key . '.count', $key . '.deferred']), 'Bulk deletion failed.');
        ensure(!$cache->has($key . '.count') && !$cache->has($key . '.deferred'), 'Deleted keys remained.');
        $phase = explode('-', $id, 2)[0];
        $value = $cache->remember('shared.summary.' . $phase, function () use ($phase): string {
            ensure(file_put_contents($this->root . '/summary-loads-' . $phase . '.txt', "load\n", FILE_APPEND | LOCK_EX) !== false, 'Cannot record authoritative load.');
            usleep(100_000);
            return 'summary-v1';
        }, 3600);
        ensure($value === 'summary-v1', 'Shared summary mismatch.');
        if ($this->backend === 'cache-valkey') {
            $atomic = $cache->atomic();
            ensure($atomic !== null && $atomic->setIfAbsent('claim.' . $id, 'pending', 3600), 'Valkey atomic create failed.');
            ensure($atomic->compareAndSet('claim.' . $id, 'pending', 'complete', 3600), 'Valkey CAS failed.');
            ensure(!$atomic->compareAndSet('claim.' . $id, 'pending', 'duplicate', 3600), 'Stale CAS accepted.');
            ensure($atomic->getAndDelete('claim.' . $id) === 'complete' && $atomic->getAndDelete('claim.' . $id) === null, 'Valkey atomic consume/replay failed.');
        }
    }

    public function verify(int $expected): void
    {
        $cache = $this->cache();
        $foreign = $this->cache('lab-foreign');
        $seen = 0;
        foreach ([1, 4, 8] as $concurrency) {
            for ($worker = 1; $worker <= $concurrency; ++$worker) {
                for ($i = 0; $i < 40 && $seen < $expected; ++$i) {
                    $id = $concurrency . '-' . $worker . '-' . $i;
                    $key = 'order.' . $id;
                    ensure($cache->get($key) === ['id' => $id, 'cents' => 1599, 'currency' => 'USD'], 'Committed order cache record missing.');
                    ensure($foreign->get($key) === 'foreign', 'Foreign namespace record missing.');
                    ++$seen;
                }
            }
            ensure(file_get_contents($this->root . '/summary-loads-' . $concurrency . '.txt') === "load\n", 'Shared remember performed duplicate authoritative loads.');
            if ($seen === $expected) {
                break;
            }
        }
        ensure($seen === $expected, 'Cache reconciliation count mismatch.');
        ensure($cache->set('expiry.probe', 'short-lived', 1), 'TTL probe save failed.');
        sleep(2);
        ensure(!$cache->has('expiry.probe'), 'Expired cache value still visible.');
    }
}
