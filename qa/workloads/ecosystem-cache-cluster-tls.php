<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;

function labClusterTlsClient(string $certificate, string $peer = '127.0.0.1', bool $tls = true): RedisCluster
{
    $seeds = array_map(static fn(int $port): string => ($tls ? 'tls://' : '') . '127.0.0.1:' . $port, range(17379, 17381));

    return new RedisCluster(null, $seeds, 1.5, 1.5, false, null, [
        'cafile' => $certificate,
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $peer,
    ]);
}

function labClusterTlsCache(string $namespace): Cache
{
    return Cache::redisCluster($namespace, client: labClusterTlsClient(getenv('LAB_CLUSTER_TLS_ROOT') . '/cert.pem'),
        options: new CacheOptions(failOpen: false));
}

function clusterTlsContracts(string $phase): void
{
    $root = getenv('LAB_CLUSTER_TLS_ROOT');
    $cert = $root . '/cert.pem';
    if ($phase === 'trust') {
        $client = labClusterTlsClient($cert);
        $masters = $client->_masters();
        verify(count($masters) === 3, 'TLS cluster discovery did not expose three masters.');
        foreach ($masters as $master) {
            verify($client->ping($master) !== false, 'Discovered TLS node unreachable.');
        }
        foreach ([[$root . '/wrong.pem', '127.0.0.1', true], [$cert, 'wrong.example.test', true], [$cert, '127.0.0.1', false]] as [$ca, $peer, $tls]) {
            expectFailure(static fn() => @labClusterTlsClient($ca, $peer, $tls), RedisClusterException::class, 'Invalid TLS trust or plaintext cluster accepted.');
        }
    } elseif ($phase === 'bulk') {
        $cache = labClusterTlsCache('lab-cluster-tls');
        $other = labClusterTlsCache('lab-cluster-other');
        $values = [];
        for ($i = 0; $i < 240; ++$i) {
            $values['order-' . $i] = ['id' => $i, 'payload' => hash('sha256', (string) $i, true)];
        }
        verify($cache->setMultiple($values, 600), 'TLS cluster bulk write failed.');
        $fresh = labClusterTlsCache('lab-cluster-tls');
        verify(iterator_to_array($fresh->getMultiple(array_keys($values))) === $values, 'Fresh TLS cluster bulk read diverged.');
        $native = labClusterTlsClient($cert);
        foreach ($native->_masters() as $master) {
            verify($native->dbSize($master) > 0, 'Bulk workload did not reach every real cluster master.');
        }
        verify($other->set('order-0', 'foreign', 600) && $cache->clear(), 'TLS namespace clear failed.');
        verify(!$fresh->has('order-0') && $other->get('order-0') === 'foreign', 'TLS namespace clear crossed tenant boundary.');
    } elseif ($phase === 'atomic') {
        $cache = labClusterTlsCache('lab-cluster-atomic');
        $atomic = $cache->atomic();
        verify($atomic !== null, 'Native cluster atomic capability absent.');
        $results = parallelRace(static function (int $worker): int {
            $atomic = labClusterTlsCache('lab-cluster-atomic')->atomic();
            verify($atomic !== null, 'Worker atomic capability absent.');
            return (int) $atomic->setIfAbsent('claim', $worker, 300);
        }, 12);
        verify(array_sum($results) === 1, 'TLS cluster claim admitted multiple owners.');
        $owner = $cache->get('claim');
        verify(is_int($owner) && !$atomic->compareAndSet('claim', (string) $owner, 'invalid', 300), 'TLS strict CAS accepted wrong type.');
        verify($atomic->compareAndSet('claim', $owner, 'settled', 300), 'TLS cluster CAS failed.');
        verify($atomic->getAndDelete('claim') === 'settled' && !$cache->has('claim'), 'TLS cluster consume failed.');
    } elseif ($phase === 'seed') {
        verify(labClusterTlsCache('lab-cluster-restart')->set('record', 'confirmed', 900), 'TLS cluster durable seed failed.');
    } elseif ($phase === 'offline') {
        expectFailure(static fn() => @labClusterTlsCache('lab-cluster-restart'), RedisClusterException::class, 'Stopped cluster reported reachable.');
    } elseif ($phase === 'recover') {
        $cache = labClusterTlsCache('lab-cluster-restart');
        verify($cache->get('record') === 'confirmed', 'Restart lost confirmed TLS data.');
        verify($cache->set('record', 'replacement', 300) && labClusterTlsCache('lab-cluster-restart')->get('record') === 'replacement', 'TLS cluster failed to recover writes.');
    } else {
        throw new InvalidArgumentException('Unknown TLS cluster phase.');
    }
    echo "Native Redis Cluster TLS $phase passed\n";
}
