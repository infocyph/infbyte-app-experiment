<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;

function scyllaWorkload(): void
{
    verify(class_exists('Cassandra'), 'Native Cassandra driver is required for Scylla.');
    $cache = Cache::scylla('lab-scylla');
    $other = Cache::scylla('lab-scylla-other');
    $values = [];
    for ($i = 0; $i < 240; ++$i) {
        $values['item-' . $i] = ['ordinal' => $i, 'payload' => "binary\x00" . $i];
    }
    $cache->setMultiple($values, 300);
    verify(iterator_to_array($cache->getMultiple(array_keys($values))) === $values, 'Scylla bulk round trip mismatch.');
    $other->set('item-0', 'independent', 300);
    $cache->remember('tagged', static fn (): string => 'before', 300, ['catalog']);
    $cache->invalidateTag('catalog');
    verify($cache->remember('tagged', static fn (): string => 'after', 300, ['catalog']) === 'after', 'Scylla tag invalidation failed.');
    $cache->set('expiring', 'temporary', 1);
    verify($cache->get('expiring') === 'temporary', 'Scylla TTL initial read failed.');
    $deadline = hrtime(true) + 10_000_000_000;
    do {
        usleep(100_000);
    } while ($cache->get('expiring') !== null && hrtime(true) < $deadline);
    verify($cache->get('expiring') === null, 'Scylla TTL did not expire.');
    $cache->deleteMultiple(array_keys($values));
    verify($cache->get('item-0') === null && $other->get('item-0') === 'independent', 'Scylla namespace isolation failed.');
}

function redisFailoverWorkload(bool $seed): void
{
    $seeds = array_map(static fn (int $port): string => '127.0.0.1:' . $port, range(17379, 17384));
    $cache = Cache::redisCluster('lab-failover', $seeds);
    $values = [];
    for ($i = 0; $i < 300; ++$i) {
        $values['item-' . $i] = ['ordinal' => $i, 'digest' => hash('sha256', (string) $i)];
    }
    if ($seed) {
        $cache->setMultiple($values, 600);
    }
    verify(iterator_to_array($cache->getMultiple(array_keys($values))) === $values, 'Redis Cluster replicated data mismatch.');
    if (!$seed) {
        $cache->set('after-election', 'writable', 300);
        verify($cache->get('after-election') === 'writable', 'Redis Cluster not writable after election.');
    }
}
