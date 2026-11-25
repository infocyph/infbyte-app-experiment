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

function cacheTtlWorkload(string $backend): void
{
    [$cache, $peer, $foreign] = cacheContractPeers($backend, 'ttl-forms');
    $atomic = $cache->atomic();
    verify($atomic !== null, 'Atomic capability unavailable.');
    $expiry = new DateTimeImmutable('+10 minutes');
    verify($cache->set('absolute', 'a', $expiry) && $cache->set('interval', 'b', new DateInterval('PT10M')), 'TTL forms failed.');
    verify($atomic->setIfAbsent('atomic-interval', 1, new DateInterval('PT10M')), 'Atomic interval insertion failed.');
    verify($atomic->compareAndSet('atomic-interval', 1, 2, $expiry), 'Atomic absolute replacement failed.');
    verify($peer->get('absolute') === 'a' && $peer->get('interval') === 'b' && $peer->get('atomic-interval') === 2, 'TTL publication differs.');
    verify($foreign->set('absolute', 'foreign', 600), 'Foreign fixture failed.');
    $past = new DateTimeImmutable('-1 minute');
    verify(!$atomic->compareAndSet('atomic-interval', 2, 3, $past) && $peer->get('atomic-interval') === 2, 'Expired CAS modified value.');
    verify(!$atomic->setIfAbsent('past', 1, $past) && !$peer->has('past'), 'Expired insertion published.');
    verify($cache->set('absolute', 'ignored', $past) && !$peer->has('absolute') && $foreign->get('absolute') === 'foreign', 'Past expiry did not delete only the target.');
    expectFailure(static fn() => $cache->set('interval', 'bad', PHP_INT_MAX), InvalidCacheKey::class, 'Overflow TTL accepted.');
    expectFailure(static fn() => $cache->set('interval', 'bad', '600'), InvalidCacheKey::class, 'String TTL accepted.');
    verify($peer->get('interval') === 'b', 'Rejected TTL changed live value.');
    verify($cache->set('short', 5, new DateInterval('PT2S')), 'Short interval failed.');
    $deadline = hrtime(true) + 10_000_000_000;
    while ($peer->has('short')) {
        verify(hrtime(true) < $deadline, 'Interval expiry was not visible to peer.');
        usleep(50_000);
    }
    verify($atomic->setIfAbsent('short', 6, $expiry) && $peer->get('short') === 6, 'Expired key could not be replaced.');
    echo "Shared $backend absolute/interval TTL and rejected mutation contracts passed\n";
}

function cacheMixedAtomicWorkload(string $backend): void
{
    $root = evidenceDirectory() . '/cache-mixed-atomic';
    verify(mkdir($root, 0700), 'Atomic fixture directory unavailable.');
    $namespace = 'mixed-atomic';
    $cache = sharedAtomicCache($backend, $namespace, $root);
    $foreign = sharedAtomicCache($backend, 'foreign-mixed-atomic', $root);
    verify($cache->set('order', 1, 600) && $foreign->set('order', 99, 600), 'Mixed race seed failed.');
    $results = parallelRace(static function (int $worker) use ($backend, $namespace, $root): int {
        $atomic = sharedAtomicCache($backend, $namespace, $root)->atomic();
        verify($atomic !== null, 'Atomic capability unavailable.');
        if ($worker % 2 === 0) {
            return $atomic->compareAndSet('order', 1, 2, 600) ? 10 : 0;
        }
        $value = $atomic->getAndDelete('order', 0);
        verify(in_array($value, [0, 1, 2], true), 'Unexpected consumed value.');
        return $value;
    }, 'mixed-atomic', 12);
    $wins = array_count_values($results);
    verify(($wins[1] ?? 0) + ($wins[2] ?? 0) === 1, 'Mixed race did not consume exactly once.');
    verify(($wins[10] ?? 0) === ($wins[2] ?? 0), 'CAS and consumption cannot be linearized.');
    verify(!$cache->has('order') && $foreign->get('order') === 99, 'Mixed race left data or crossed namespace.');
    $atomic = $cache->atomic();
    verify($atomic !== null && $atomic->setIfAbsent('order', 3, 600) && $atomic->getAndDelete('order') === 3, 'Mixed race prevented subsequent ownership.');
    echo "Shared $backend mixed CAS/consume linearization and recovery passed\n";
}

function cachePayloadPolicyWorkload(string $backend): void
{
    $root = evidenceDirectory() . '/cache-payload-policy';
    verify(mkdir($root, 0700), 'Payload fixture directory unavailable.');
    $options = new \Infocyph\CacheLayer\Cache\CacheOptions(integrityKey: 'lab-payload-signing-key', maxPayloadBytes: 4096, compressionThreshold: 256, failOpen: false);
    $cache = sharedAtomicCache($backend, 'payload-policy', $root, $options);
    $peer = sharedAtomicCache($backend, 'payload-policy', $root, $options);
    $atomic = $cache->atomic();
    verify($cache->hasPayloadIntegrity() && $atomic !== null, 'Required integrity/atomic capability unavailable.');
    $value = ['binary' => "x\0\xff", 'repeat' => str_repeat('z', 1200)];
    verify($atomic->setIfAbsent('signed', $value, 600) && $peer->get('signed') === $value, 'Signed compressed record failed to roundtrip.');
    verify($atomic->compareAndSet('signed', $value, ['version' => 2], 600), 'Signed atomic replacement failed.');
    verify($peer->get('signed') === ['version' => 2], 'Signed replacement unavailable.');
    $failure = \Infocyph\CacheLayer\Exceptions\CacheBackendException::class;
    expectFailure(static fn() => $cache->set('signed', str_repeat('x', 8192), 600), $failure, 'Oversized expanded record accepted.');
    expectFailure(static fn() => $atomic->compareAndSet('signed', ['version' => 2], new stdClass(), 600), $failure, 'Atomic object policy bypassed.');
    expectFailure(static fn() => $atomic->setIfAbsent('closure', static fn(): int => 1, 600), $failure, 'Atomic closure policy bypassed.');
    verify($peer->get('signed') === ['version' => 2] && !$peer->has('closure'), 'Rejected payload modified persisted state.');
    verify($cache->set('wrong-key', $value, 600), 'Wrong-key fixture failed.');
    $wrong = sharedAtomicCache($backend, 'payload-policy', $root, new \Infocyph\CacheLayer\Cache\CacheOptions(integrityKey: 'different-lab-signing-key', failOpen: false));
    verify(!$wrong->has('wrong-key'), 'Record authenticated with unrelated key.');
    verify($atomic->getAndDelete('signed') === ['version' => 2] && !$peer->has('signed'), 'Signed atomic consumption failed.');
    echo "Shared $backend signed/compressed payload and atomic policy contracts passed\n";
}
