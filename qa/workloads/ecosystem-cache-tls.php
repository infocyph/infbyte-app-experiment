<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;

function labTlsRedis(string $certificate): Redis
{
    $client = new Redis();
    verify($client->connect('tls://localhost', 17390, 2.0, null, 0, 2.0, [
        'stream' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => 'localhost',
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
        $cache = Cache::redis('lab-tls-stampede', client: labTlsRedis($certificate), options: new CacheOptions(failOpen: false));
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

    $cache = Cache::redis('lab-tls-stampede', client: labTlsRedis($certificate), options: new CacheOptions(failOpen: false));
    verify($cache->get('cold') === 42, 'Fresh TLS client missed cached value.');
    $atomic = $cache->atomic();
    verify($atomic !== null && $atomic->setIfAbsent('claim', 1, 60), 'Atomic claim failed.');
    verify(!$atomic->compareAndSet('claim', '1', 2, 60) && $atomic->compareAndSet('claim', 1, 2, 60), 'Atomic type boundary failed.');
    verify($atomic->getAndDelete('claim') === 2 && !$cache->has('claim'), 'Atomic consume failed.');
    echo "TLS trust, plaintext rejection, 12-worker single computation and atomic reconciliation passed\n";
}
