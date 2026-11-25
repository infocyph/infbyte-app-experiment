<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\CacheLayer\Counter\Exception\AtomicCounterException;

function labTlsRedis(string $certificate, string $peer = 'localhost'): Redis
{
    $client = new Redis();
    verify($client->connect('tls://localhost', 17390, 2.0, null, 0, 2.0, [
        'stream' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $peer,
            'cafile' => $certificate,
        ],
    ]), 'TLS connection failed.');

    return $client;
}

function cacheTlsWorkload(): void
{
    $root = (string) getenv('LAB_CACHE_TLS_ROOT');
    $certificate = $root . '/cert.pem';
    $rejected = false;
    try {
        $wrong = @labTlsRedis($root . '/wrong.pem');
        $wrong->ping();
    } catch (RedisException|RuntimeException) {
        $rejected = true;
    }
    verify($rejected, 'Incorrect CA accepted.');
    $rejected = false;
    try {
        $wrong = @labTlsRedis($certificate, 'wrong.example.test');
        $wrong->ping();
    } catch (RedisException|RuntimeException) {
        $rejected = true;
    }
    verify($rejected, 'Wrong peer identity accepted.');
    $plain = new Redis();
    $rejected = false;
    try {
        $rejected = !@$plain->connect('127.0.0.1', 17390, 2.0, null, 0, 2.0)
            || !@$plain->ping();
    } catch (RedisException) {
        $rejected = true;
    }
    verify($rejected, 'Plaintext accepted on TLS-only port.');

    $results = parallelRace(static function () use ($certificate, $root): int {
        $cache = labTlsCache($certificate);
        $value = $cache->remember('cold', static function () use ($root): int {
            verify(file_put_contents($root . '/computes.log', "computed\n", FILE_APPEND | LOCK_EX) !== false, 'Compute evidence unavailable.');
            usleep(100000);

            return 42;
        }, 60);
        verify($value === 42, 'Cold-key result mismatch.');

        return $value;
    }, 'cache-tls', 12);
    verify($results === array_fill(0, 12, 42), 'Worker results diverged.');
    verify(file_get_contents($root . '/computes.log') === "computed\n", 'Cold-key computation ran more than once.');

    $cache = labTlsCache($certificate);
    verify($cache->get('cold') === 42, 'Fresh TLS client missed cached value.');
    $atomic = $cache->atomic();
    verify($atomic !== null && $atomic->setIfAbsent('claim', 1, 60), 'Atomic claim failed.');
    verify(!$atomic->compareAndSet('claim', '1', 2, 60) && $atomic->compareAndSet('claim', 1, 2, 60), 'Atomic type boundary failed.');
    verify($atomic->getAndDelete('claim') === 2 && !$cache->has('claim'), 'Atomic consume failed.');
    echo "TLS trust, plaintext rejection, 12-worker single computation and atomic reconciliation passed\n";
}

function labTlsCache(string $certificate): Cache
{
    $client = labTlsRedis($certificate);
    $options = new CacheOptions(failOpen: false);

    return getenv('LAB_TLS_BACKEND') === 'valkey'
        ? Cache::valkey('lab-tls-stampede', client: $client, options: $options)
        : Cache::redis('lab-tls-stampede', client: $client, options: $options);
}

function labTlsCounter(Redis $client): AtomicCounterStoreInterface
{
    return getenv('LAB_TLS_BACKEND') === 'valkey'
        ? AtomicCounters::valkey('lab-tls-counter', client: $client)
        : AtomicCounters::redis('lab-tls-counter', client: $client);
}

function counterTlsWorkload(string $phase): void
{
    $certificate = getenv('LAB_CACHE_TLS_ROOT') . '/cert.pem';
    if ($phase === 'offline') {
        // Exercise connection admission during an actual service outage.
        $rejected = false;
        try {
            $client = labTlsRedis($certificate);
            labTlsCounter($client)->increment('durable');
        } catch (RedisException|AtomicCounterException|RuntimeException) {
            $rejected = true;
        }
        verify($rejected, 'Offline increment was reported as successful.');
        echo "Offline counter connection failed closed\n";
        return;
    }

    $counter = labTlsCounter(labTlsRedis($certificate));
    if ($phase === 'seed') {
        verify($counter->increment('durable', 20)->value === 20, 'Counter seed mismatch.');
        verify($counter->decrement('durable', 3)->value === 17, 'Counter decrement mismatch.');
        verify($counter->increment('precision', 9_007_199_254_740_993)->value === 9_007_199_254_740_993, 'Precision seed mismatch.');
        verify($counter->increment('expiring', 1, 120)->initialized, 'Expiring seed failed.');
    } elseif ($phase === 'recover') {
        verify($counter->get('durable') === 17, 'Offline increment changed durable value.');
        verify($counter->get('precision') === 9_007_199_254_740_993, 'Persistent integer precision lost.');
        verify($counter->get('expiring') === 1, 'Live TTL lost during restart.');
        $results = parallelRace(static function () use ($certificate): int {
            return labTlsCounter(labTlsRedis($certificate))->increment('durable')->value;
        }, 'counter-restart', 12);
        sort($results);
        verify($results === range(18, 29) && $counter->get('durable') === 29, 'Recovered counter increments diverged.');
    } else {
        throw new InvalidArgumentException('Unknown counter phase.');
    }
    echo "TLS counter $phase passed\n";
}
