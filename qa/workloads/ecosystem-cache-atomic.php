<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;

function sharedAtomicCache(string $backend, string $namespace, string $root, ?CacheOptions $options = null): Cache
{
    $options ??= new CacheOptions(failOpen: false);
    $db = (string) getenv('DB_BACKEND');
    $dsn = match ($db) {
        'pgsql' => 'pgsql:host=127.0.0.1;port=5432;dbname=experiment',
        'mysql' => 'mysql:host=127.0.0.1;port=3306;dbname=experiment',
        'mariadb' => 'mysql:host=127.0.0.1;port=3307;dbname=experiment',
        default => throw new InvalidArgumentException('Unknown shared cache database.'),
    };
    return match ($backend) {
        'redis-cluster' => Cache::redisCluster($namespace, ['127.0.0.1:17379', '127.0.0.1:17380', '127.0.0.1:17381'], options: $options),
        'mongodb' => Cache::mongodb($namespace, database: 'experiment', options: $options),
        'scylla' => Cache::scylla($namespace, options: $options),
        'redis' => Cache::redis($namespace, options: $options),
        'valkey' => Cache::valkey($namespace, 'valkey://127.0.0.1:6380', options: $options),
        'memcached' => Cache::memcached($namespace, options: $options),
        'file' => Cache::file($namespace, $root . '/file', $options),
        'phpfiles' => Cache::phpFiles($namespace, $root . '/phpfiles', $options),
        'sqlite' => Cache::sqlite($namespace, $root . '/cache.sqlite', $options),
        'pdo' => Cache::pdo($namespace, $dsn, 'experiment', 'experiment', options: $options),
        default => throw new InvalidArgumentException('Unknown shared atomic adapter.'),
    };
}

function cacheAtomicWorkload(string $backend): void
{
    $root = evidenceDirectory() . '/atomic-contract';
    verify(mkdir($root, 0700), 'Atomic fixture directory unavailable.');
    $namespace = 'atomic-contract';
    $cache = sharedAtomicCache($backend, $namespace, $root);
    $atomic = $cache->atomic();
    verify($atomic !== null, 'Documented shared atomic capability absent.');
    verify(!$atomic->compareAndSet('absent', null, 'new', 600), 'Absent key compared equal to cached null.');
    verify($atomic->setIfAbsent('nullable', null, 600) && $cache->has('nullable') && !$atomic->setIfAbsent('nullable', 'bad', 600), 'Cached null treated as absence.');
    verify($atomic->compareAndSet('nullable', null, false, 600) && !$atomic->compareAndSet('nullable', 0, 'bad', 600), 'Strict CAS confused null, false or zero.');
    verify($atomic->getAndDelete('nullable', 'missing') === false && $atomic->getAndDelete('nullable', 'missing') === 'missing', 'False value consumption or deletion mismatch.');
    verify(!$atomic->setIfAbsent('ttl-zero', 'bad', 0) && !$cache->has('ttl-zero'), 'Nonpositive insertion TTL mutated cache.');
    verify($cache->set('strict', 1, 600) && !$atomic->compareAndSet('strict', '1', 'bad', 600) && !$atomic->compareAndSet('strict', 1, 'bad', 0) && $cache->get('strict') === 1, 'Strict comparison or nonpositive CAS TTL failed.');
    verify($atomic->setIfAbsent('expire', 'old', 1), 'TTL seed failed.');
    $deadline = hrtime(true) + 10_000_000_000;
    while ($cache->has('expire')) {
        verify(hrtime(true) < $deadline, 'Atomic TTL did not expire.');
        usleep(50_000);
    }
    verify($atomic->setIfAbsent('expire', 'new', 600) && $atomic->getAndDelete('expire') === 'new', 'Expired key did not allow new generation.');

    $other = sharedAtomicCache($backend, 'foreign-atomic', $root);
    verify($other->set('race', 'foreign', 600), 'Foreign namespace seed failed.');
    $race = parallelRace(static function (int $worker) use ($backend, $namespace, $root): int {
        $active = sharedAtomicCache($backend, $namespace, $root)->atomic();
        verify($active !== null, 'Worker atomic capability absent.');
        return (int) $active->setIfAbsent('race', $worker, 600);
    }, 'atomic-insert', 12);
    verify(array_sum($race) === 1 && is_int($cache->get('race')), 'Concurrent insertion did not elect one winner.');
    verify($cache->set('cas', 'initial', 600), 'CAS seed failed.');
    $race = parallelRace(static function (int $worker) use ($backend, $namespace, $root): int {
        $active = sharedAtomicCache($backend, $namespace, $root)->atomic();
        verify($active !== null, 'Worker atomic capability absent.');
        return (int) $active->compareAndSet('cas', 'initial', $worker, 600);
    }, 'atomic-cas', 12);
    verify(array_sum($race) === 1 && is_int($cache->get('cas')), 'Concurrent CAS did not elect one winner.');
    verify($cache->set('consume', false, 600), 'Consume seed failed.');
    $race = parallelRace(static function () use ($backend, $namespace, $root): int {
        $active = sharedAtomicCache($backend, $namespace, $root)->atomic();
        verify($active !== null, 'Worker atomic capability absent.');
        $result = $active->getAndDelete('consume', 'missing');
        verify($result === false || $result === 'missing', 'Unexpected consumed value.');
        return (int) ($result === false);
    }, 'atomic-consume', 12);
    verify(array_sum($race) === 1 && !$cache->has('consume') && $other->get('race') === 'foreign', 'Consume or namespace isolation failed.');
    echo "Shared $backend strict atomic contracts and three independent 12-process one-winner races passed\n";
}
