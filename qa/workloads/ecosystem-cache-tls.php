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

function labMongoTlsClient(string $certificate, string $host = 'localhost', bool $tls = true): \MongoDB\Client
{
    $options = ['serverSelectionTimeoutMS' => 2500, 'connectTimeoutMS' => 1000, 'socketTimeoutMS' => 3000, 'directConnection' => true, 'tls' => $tls];
    if ($tls) {
        $options['tlsCAFile'] = $certificate;
    }
    return new \MongoDB\Client("mongodb://$host:27018", $options);
}

function labMongoTlsCache(string $namespace): Cache
{
    return Cache::mongodb($namespace, client: labMongoTlsClient(getenv('LAB_REMOTE_TLS_ROOT') . '/cert.pem'),
        database: 'experiment', options: new CacheOptions(integrityKey: 'fixture-remote-tls-integrity-key', failOpen: false));
}

function mongoTlsContracts(string $phase): void
{
    $root = (string) getenv('LAB_REMOTE_TLS_ROOT');
    $cert = $root . '/cert.pem';
    if ($phase === 'trust') {
        $client = labMongoTlsClient($cert);
        verify((int) $client->selectDatabase('admin')->command(['ping' => 1])->toArray()[0]['ok'] === 1, 'Trusted native TLS handshake failed.');
        foreach ([
            [$root . '/wrong.pem', 'localhost', true],
            [$cert, '127.0.0.1', true],
            [$cert, 'localhost', false],
        ] as [$ca, $host, $tls]) {
            expectFailure(static fn() => labMongoTlsClient($ca, $host, $tls)->selectDatabase('admin')->command(['ping' => 1])->toArray(),
                \MongoDB\Driver\Exception\ConnectionException::class, 'Untrusted or plaintext MongoDB connection accepted.');
        }
        $cache = labMongoTlsCache('lab-tls-trust');
        $peer = labMongoTlsCache('lab-tls-trust');
        $foreign = labMongoTlsCache('lab-tls-foreign');
        $value = ['binary' => "a\0\xff", 'nullable' => null, 'false' => false];
        verify($cache->set('record', $value, 300) && $peer->get('record') === $value, 'TLS cache independent payload mismatch.');
        verify($foreign->set('record', 'foreign', 300) && $cache->clear() && !$peer->has('record') && $foreign->get('record') === 'foreign', 'TLS namespace clear crossed boundary.');
    } elseif ($phase === 'atomic') {
        $cache = labMongoTlsCache('lab-tls-atomic');
        $atomic = $cache->atomic();
        verify($atomic !== null, 'Native MongoDB atomic capability missing.');
        $results = parallelRace(static function (int $worker): int {
            $atomic = labMongoTlsCache('lab-tls-atomic')->atomic();
            verify($atomic !== null, 'Worker atomic capability missing.');
            return $atomic->setIfAbsent('claim', $worker, 300) ? 1 : 0;
        }, 'mongo-tls-claim', 12);
        verify(array_sum($results) === 1, 'TLS atomic race admitted multiple owners.');
        $value = $cache->get('claim');
        verify(is_int($value) && !$atomic->compareAndSet('claim', (string) $value, 'invalid', 300), 'TLS atomic type comparison differed.');
        verify($atomic->compareAndSet('claim', $value, 'settled', 300) && $atomic->getAndDelete('claim') === 'settled' && !$cache->has('claim'), 'TLS CAS/consume reconciliation failed.');
    } elseif ($phase === 'offline') {
        expectFailure(static fn() => labMongoTlsClient($cert)->selectDatabase('admin')->command(['ping' => 1])->toArray(),
            \MongoDB\Driver\Exception\ConnectionException::class, 'Stopped native TLS server reported reachable.');
        expectFailure(static fn() => labMongoTlsCache('lab-tls-restart')->set('durable', 'rejected', 300),
            \Infocyph\CacheLayer\Exceptions\CacheBackendException::class, 'Offline TLS write reported success.');
    } elseif ($phase === 'seed') {
        $cache = labMongoTlsCache('lab-tls-restart');
        verify($cache->set('durable', 'confirmed', 300), 'Durable TLS cache seed failed.');
    } elseif ($phase === 'recover') {
        $cache = labMongoTlsCache('lab-tls-restart');
        verify($cache->get('durable') === 'confirmed', 'Offline TLS write altered committed value.');
        verify($cache->set('durable', 'replacement', 300) && labMongoTlsCache('lab-tls-restart')->get('durable') === 'replacement', 'Recovered native TLS publication failed.');
    } else {
        throw new InvalidArgumentException('Unknown native MongoDB TLS phase.');
    }
    echo "Native MongoDB TLS $phase completed\n";
}

function cacheTlsLiveOutage(string $contract): void
{
    $root = (string) getenv('LAB_CACHE_TLS_ROOT');
    $make = static function (string $namespace, bool $open) use ($root): Cache {
        $client = labTlsRedis($root . '/cert.pem');
        $options = new CacheOptions(failOpen: $open);

        return getenv('LAB_TLS_BACKEND') === 'valkey'
            ? Cache::valkey($namespace, client: $client, options: $options)
            : Cache::redis($namespace, client: $client, options: $options);
    };
    $closed = $make('lab-tls-live', false);
    $open = $make('lab-tls-live', true);
    $foreign = $make('lab-tls-foreign', false);
    $value = ['value' => 42, 'binary' => "a\0\xff"];
    verify($closed->set('durable', $value, 600) && $open->get('durable') === $value
        && $foreign->set('durable', 'foreign', 600), 'TLS seed or namespace isolation failed.');
    $atomic = $closed->atomic();
    $openAtomic = $open->atomic();
    verify($atomic !== null && $openAtomic !== null && $atomic->setIfAbsent('claim', 1, 600), 'TLS atomic seed failed.');
    verify(touch($root . '/seeded'), 'TLS outage readiness failed.');
    cacheWaitMarker($root . '/stopped');
    $exception = \Infocyph\CacheLayer\Exceptions\CacheBackendException::class;
    match ($contract) {
        'read' => (static function () use ($closed, $open, $exception): void {
            expectFailure(static fn() => $closed->get('durable'), $exception, 'Strict TLS read concealed outage.');
            verify($open->get('durable', 'fallback') === 'fallback', 'Open TLS read did not return default.');
        })(),
        'write' => (static function () use ($closed, $open, $exception): void {
            expectFailure(static fn() => $closed->set('lost', 1, 600), $exception, 'Strict TLS write reported success.');
            verify(!$open->set('durable', 'lost', 600), 'Open TLS write reported success.');
        })(),
        'atomic' => (static function () use ($atomic, $openAtomic, $exception): void {
            expectFailure(static fn() => $atomic->compareAndSet('claim', 1, 2, 600), $exception, 'Strict TLS CAS concealed outage.');
            verify(!$openAtomic->setIfAbsent('lost', 1, 600) && $openAtomic->getAndDelete('claim', 'fallback') === 'fallback',
                'Open TLS atomics reported success during outage.');
        })(),
        default => throw new InvalidArgumentException('Unknown TLS live outage contract.'),
    };
    verify(touch($root . '/observed'), 'TLS outage observation unavailable.');
    cacheWaitMarker($root . '/restarted');
    $replacement = $make('lab-tls-live', false);
    $peer = $make('lab-tls-foreign', false);
    verify($replacement->get('durable') === $value && !$replacement->has('lost') && $replacement->get('claim') === 1
        && $peer->get('durable') === 'foreign', 'Recovered TLS cache lost confirmed state or applied rejected mutation.');
    $replacementAtomic = $replacement->atomic();
    verify($replacementAtomic !== null && $replacementAtomic->compareAndSet('claim', 1, 2, 600)
        && $replacementAtomic->getAndDelete('claim') === 2 && !$replacement->has('claim'), 'Fresh TLS client did not recover atomic capability.');
    verify($replacement->set('recovered', true, 600) && $make('lab-tls-live', false)->get('recovered') === true, 'Replacement publication failed.');
    echo "Connected TLS $contract outage and durable recovery passed\n";
}
