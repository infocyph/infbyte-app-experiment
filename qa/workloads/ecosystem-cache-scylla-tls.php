<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;

function labScyllaTlsSession(?string $certificate, bool $identity = true, ?string $keyspace = 'cachelayer'): object
{
    $builder = Cassandra::cluster()->withContactPoints('127.0.0.1')->withPort(9042)
        ->withPersistentSessions(false)->withConnectTimeout(2)->withRequestTimeout(3);
    if ($certificate !== null) {
        $flags = Cassandra::VERIFY_PEER_CERT;
        if ($identity) {
            $flags |= Cassandra::VERIFY_PEER_IDENTITY;
        }
        $ssl = Cassandra::ssl()->withTrustedCerts($certificate)->withVerifyFlags($flags)->build();
        $builder->withSSL($ssl);
    }

    return $builder->build()->connect($keyspace, 5);
}

function labScyllaTlsCache(string $namespace): Cache
{
    return Cache::scylla($namespace, session: labScyllaTlsSession(getenv('LAB_SCYLLA_TLS_ROOT') . '/cert.pem'),
        options: new CacheOptions(failOpen: false));
}

function scyllaTlsContracts(string $phase): void
{
    $root = getenv('LAB_SCYLLA_TLS_ROOT');
    $cert = $root . '/cert.pem';
    if ($phase === 'prepare') {
        $session = labScyllaTlsSession($cert, keyspace: null);
        $session->execute(new Cassandra\SimpleStatement("CREATE KEYSPACE IF NOT EXISTS cachelayer WITH replication = {'class':'SimpleStrategy','replication_factor':1}"));
    } elseif ($phase === 'trust') {
        $session = labScyllaTlsSession($cert);
        verify(count($session->execute(new Cassandra\SimpleStatement('SELECT release_version FROM system.local'))) === 1, 'Native verified CQL query failed.');
        foreach ([$root . '/wrong.pem', null] as $ca) {
            expectFailure(static fn() => labScyllaTlsSession($ca), Cassandra\Exception\NoHostsAvailableException::class, 'Invalid CA or plaintext CQL accepted.');
        }
    } elseif ($phase === 'identity') {
        // The same trusted wrong-identity certificate supports a read-only CA-only
        // positive control, never the cache adapter. The strict client must reject it.
        $session = labScyllaTlsSession($cert, identity: false, keyspace: null);
        verify(count($session->execute(new Cassandra\SimpleStatement('SELECT release_version FROM system.local'))) === 1, 'Wrong-identity positive control did not reach CQL.');
        expectFailure(static fn() => labScyllaTlsSession($cert, keyspace: null), Cassandra\Exception\NoHostsAvailableException::class, 'Wrong server identity accepted.');
    } elseif ($phase === 'payload') {
        $cache = labScyllaTlsCache('scylla-tls-payload');
        $foreign = labScyllaTlsCache('scylla-tls-foreign');
        $values = [];
        for ($i = 0; $i < 24; ++$i) {
            $values['receipt-' . $i] = ['id' => $i, 'body' => hash('sha256', (string) $i, true)];
        }
        verify($cache->setMultiple($values, 600), 'TLS CQL bulk mutation failed.');
        $fresh = labScyllaTlsCache('scylla-tls-payload');
        verify(iterator_to_array($fresh->getMultiple(array_keys($values))) === $values, 'Fresh TLS CQL bulk read diverged.');
        verify($foreign->set('receipt-0', 'foreign', 600) && $cache->deleteMultiple(array_keys($values)), 'TLS CQL namespace mutation failed.');
        verify(!$fresh->has('receipt-0') && $foreign->get('receipt-0') === 'foreign', 'TLS namespace crossed ownership.');
        verify($cache->atomic() === null, 'Scylla advertised unsupported atomic capability.');
    } elseif ($phase === 'seed') {
        verify(labScyllaTlsCache('scylla-tls-restart')->set('record', 'confirmed', 900), 'TLS CQL seed failed.');
    } elseif ($phase === 'offline') {
        expectFailure(static fn() => labScyllaTlsSession($cert), Cassandra\Exception\NoHostsAvailableException::class, 'Stopped TLS CQL node reported reachable.');
    } elseif ($phase === 'recover') {
        $cache = labScyllaTlsCache('scylla-tls-restart');
        verify($cache->get('record') === 'confirmed', 'Restart lost confirmed TLS CQL value.');
        verify($cache->set('record', 'replacement', 300) && labScyllaTlsCache('scylla-tls-restart')->get('record') === 'replacement', 'TLS CQL recovery write failed.');
    } else {
        throw new InvalidArgumentException('Unknown Scylla TLS phase.');
    }
    echo "Native Scylla CQL TLS $phase passed\n";
}
