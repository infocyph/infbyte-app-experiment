<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Psr\SimpleCache\InvalidArgumentException as InvalidCacheKey;

function cacheContractPeers(string $backend, string $family): array
{
    $root = evidenceDirectory() . '/cache-' . $family;
    verify(mkdir($root, 0700), 'Cache contract fixture directory unavailable.');
    $namespace = 'contract-' . $family;
    return [
        sharedAtomicCache($backend, $namespace, $root),
        sharedAtomicCache($backend, $namespace, $root),
        sharedAtomicCache($backend, 'foreign-' . $namespace, $root),
    ];
}

function cacheBulkWorkload(string $backend): void
{
    [$cache, $peer, $foreign] = cacheContractPeers($backend, 'bulk');
    $values = ['nil' => null, 'zero' => 0, 'false' => false, 'binary' => "a\0\xffb"];
    verify($cache->setMultiple((static function () use ($values): Generator {
        yield from $values;
    })(), 600), 'Iterable bulk write failed.');
    $actual = iterator_to_array($peer->getMultiple([...array_keys($values), 'missing'], 'absent'));
    verify($actual === [...$values, 'missing' => 'absent'], 'Bulk value or miss semantics differ.');
    $items = iterator_to_array($peer->getItems(['nil', 'false', 'missing']));
    verify($items['nil']->isHit() && $items['nil']->get() === null && $items['false']->isHit() && $items['false']->get() === false && !$items['missing']->isHit(), 'PSR item presence semantics differ.');

    $deferred = $cache->getItem('deferred')->set(['version' => 1])->expiresAfter(new DateInterval('PT10M'));
    verify($cache->saveDeferred($deferred) && $cache->commit(), 'Deferred commit failed.');
    verify($peer->get('deferred') === ['version' => 1], 'Committed item unavailable to independent peer.');
    verify($cache->commit(), 'Empty repeated commit failed.');

    $rejected = false;
    try {
        $cache->setMultiple(['partial' => 1, 'bad:key' => 2], 600);
    } catch (InvalidCacheKey $exception) {
        $rejected = true;
    }
    verify($rejected && !$peer->has('partial'), 'Invalid bulk key permitted partial publication.');
    verify($foreign->set('zero', 99, 600), 'Foreign namespace seed failed.');
    verify($cache->setMultiple(['zero' => 1], 0) && !$peer->has('zero'), 'Zero TTL bulk write did not delete.');
    verify($cache->deleteMultiple((static function (): Generator {
        yield 'nil';
        yield 'false';
        yield 'not-present';
    })()), 'Iterable deletion failed.');
    verify(!$peer->has('nil') && !$peer->has('false') && $peer->get('binary') === $values['binary'] && $foreign->get('zero') === 99, 'Bulk deletion affected unrelated values.');
    echo "Shared $backend PSR bulk/deferred/validation contracts passed\n";
}

function cacheTagsWorkload(string $backend): void
{
    [$cache, $peer, $foreign] = cacheContractPeers($backend, 'tags');
    verify($cache->setTagged('a', 'order', ['orders'], 600), 'Order seed failed.');
    verify($cache->setTagged('b', 'both', ['orders', 'customer'], 600), 'Multi-tag seed failed.');
    verify($cache->setTagged('c', 'customer', ['customer'], 600), 'Customer seed failed.');
    verify($cache->set('plain', 'plain', 600) && $foreign->setTagged('a', 'foreign', ['orders'], 600), 'Independent seed failed.');
    verify(iterator_to_array($peer->getMultiple(['a', 'b', 'c', 'plain'])) === ['a' => 'order', 'b' => 'both', 'c' => 'customer', 'plain' => 'plain'], 'Tagged seed not visible.');
    verify($peer->invalidateTag('orders'), 'Peer tag invalidation failed.');
    verify(!$cache->has('a') && !$cache->has('b') && $cache->get('c') === 'customer' && $cache->get('plain') === 'plain', 'Single-tag invalidation scope differs.');
    verify($cache->setTagged('a', 'new', ['orders'], 600) && $peer->get('a') === 'new', 'New tag generation unavailable.');
    verify($peer->invalidateTags(['orders', 'customer']), 'Multi-tag invalidation failed.');
    verify(iterator_to_array($cache->getMultiple(['a', 'b', 'c', 'plain'], 'absent')) === ['a' => 'absent', 'b' => 'absent', 'c' => 'absent', 'plain' => 'plain'], 'Multi-tag invalidation scope differs.');
    verify($foreign->get('a') === 'foreign', 'Tag invalidation crossed namespace.');
    echo "Shared $backend multi-tag generation and isolation contracts passed\n";
}

function cacheRememberWorkload(string $backend): void
{
    [$cache, $peer, $foreign] = cacheContractPeers($backend, 'remember');
    verify($foreign->setTagged('projection', 'foreign', ['orders'], 600), 'Foreign projection seed failed.');
    $first = $cache->remember('projection', static function () use ($peer): string {
        verify($peer->invalidateTag('orders'), 'Resolver overlap invalidation failed.');
        return 'stale';
    }, 600, ['orders']);
    verify($first === 'stale' && !$cache->has('projection') && !$peer->has('projection'), 'Invalidated resolver result was published.');
    verify($cache->remember('projection', static fn (): string => 'fresh', 600, ['orders']) === 'fresh', 'Fresh generation resolution failed.');
    verify($peer->remember('projection', static function (): never {
        throw new LogicException('Hit executed resolver.');
    }, 600, ['orders']) === 'fresh', 'Independent peer missed published generation.');
    $failed = false;
    try {
        $cache->remember('exception', static function (): never {
            throw new DomainException('Resolver failure.');
        }, 600);
    } catch (DomainException $exception) {
        $failed = true;
    }
    verify($failed && !$peer->has('exception'), 'Failed resolver published a value or swallowed failure.');
    verify($peer->remember('exception', static fn (): int => 7, 600) === 7 && $cache->get('exception') === 7, 'Resolver failure prevented recovery.');
    verify($cache->remember('nullable', static fn () => null, 600) === null && $peer->has('nullable'), 'Null result lost presence.');
    verify($peer->remember('nullable', static fn (): string => 'bad', 600) === null, 'Null hit recomputed.');
    verify($cache->remember('false', static fn (): bool => false, 600) === false && $peer->has('false'), 'False result lost presence.');
    verify($foreign->get('projection') === 'foreign', 'Resolver invalidation crossed namespace.');
    echo "Shared $backend remember overlap, failure recovery and strict hits passed\n";
}
