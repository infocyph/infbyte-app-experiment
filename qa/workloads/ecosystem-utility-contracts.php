<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Collection\LazyCollection;
use Infocyph\ArrayKit\Config\ConfigMerge;
use Infocyph\ArrayKit\Config\LazyFileConfig;
use Infocyph\ArrayKit\Config\ResilientLazyFileConfig;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\UID\IdComparator;

function diCacheProcessContracts(string $backend): void
{
    $root = evidenceDirectory() . '/di-process-cache';
    verify(mkdir($root, 0700), 'DI fixture root unavailable.');
    $pool = static fn() => sharedAtomicCache($backend, 'di-process', $root);
    $seed = static function () use ($pool): void {
        $builder = ContainerBuilder::create('di-process-seed')
            ->definitionCache($pool(), 'di-process', 'release-one', false)
            ->factory('configuration', static fn(): array => ['tenant' => 'alpha', 'zero' => 0, 'disabled' => false, 'empty' => null])
            ->cacheDefinition('configuration');
        verify($builder->warmDefinitionCache()['written'] === 1, 'Definition seed failed.');
    };
    $seed();
    $results = parallelRace(static function () use ($pool): int {
        $builder = ContainerBuilder::create('di-process-peer')
            ->definitionCache($pool(), 'di-process', 'release-one', false)
            ->factory('configuration', static fn() => throw new LogicException('Warm factory executed.'))
            ->cacheDefinition('configuration');
        $runtime = $builder->build();
        verify($runtime->get('configuration') === ['tenant' => 'alpha', 'zero' => 0, 'disabled' => false, 'empty' => null], 'Independent process read differs.');
        return 1;
    }, 'di-process-cache', 12);
    verify(count($results) === 12 && array_sum($results) === 12, 'Process reuse failed.');
    $replacement = ContainerBuilder::create('di-process-next')
        ->definitionCache($pool(), 'di-process', 'release-two', false)
        ->factory('configuration', static fn(): array => ['tenant' => 'beta'])
        ->cacheDefinition('configuration')->build();
    verify($replacement->get('configuration') === ['tenant' => 'beta'], 'New generation reused previous release.');
    echo "DI cache $backend twelve independent process reads and generation isolation passed\n";
}

function lazyReplayContracts(): void
{
    $pulled = 0;
    $source = (static function () use (&$pulled): Generator {
        for ($i = 1; $i <= 8; ++$i) {
            ++$pulled;
            yield 'k' . $i => $i;
        }
    })();
    $lazy = LazyCollection::from($source);
    verify($lazy->take(0)->all() === [] && $pulled === 0, 'Zero take consumed source.');
    $a = $lazy->cursor();
    $b = $lazy->cursor();
    $a->rewind();
    $b->rewind();
    verify($a->current() === 1 && $b->current() === 1 && $pulled === 1, 'Concurrent cursors duplicated source consumption.');
    $a->next();
    $a->next();
    $b->next();
    verify($a->current() === 3 && $b->current() === 2 && $pulled === 3, 'Interleaved cursor replay differs.');
    unset($a, $b);
    verify($lazy->take(2)->all() === ['k1' => 1, 'k2' => 2] && $pulled === 3, 'Memoized prefix replay changed source.');
    $expected = ['k1' => 1, 'k2' => 2, 'k3' => 3, 'k4' => 4, 'k5' => 5, 'k6' => 6, 'k7' => 7, 'k8' => 8];
    verify($lazy->all() === $expected && $lazy->all() === $expected && $pulled === 8, 'One-shot source replay lost entries.');
    echo "Lazy one-shot interleaved cursor replay and bounded prefix consumption passed\n";
}

function lazyFactoryContracts(): void
{
    $runs = 0;
    $pulled = 0;
    $lazy = LazyCollection::fromFactory(static function () use (&$runs, &$pulled): Generator {
        ++$runs;
        for ($i = 1; $i <= 8; ++$i) {
            ++$pulled;
            yield 'k' . $i => $i;
        }
    });
    $pipeline = $lazy->filterLazy(static fn(int $v): bool => $v % 2 === 0)->mapLazy(static fn(int $v): int => $v * 10)->take(3)->chunkLazy(2, true);
    $expected = [['k2' => 20, 'k4' => 40], ['k6' => 60]];
    verify($runs === 0 && $pipeline->all() === $expected && $runs === 1 && $pulled === 6, 'Lazy pipeline overconsumed or lost keys.');
    verify($pipeline->all() === $expected && $runs === 2 && $pulled === 12, 'Factory did not produce independent traversal.');
    verify($lazy->takeUntil(static fn(int $v): bool => $v === 3)->all() === ['k1' => 1, 'k2' => 2], 'Take-until included boundary.');
    $failure = new DomainException('source failure');
    $calls = 0;
    $broken = LazyCollection::from((static function () use ($failure, &$calls): Generator {
        ++$calls;
        yield 1;
        throw $failure;
    })());
    for ($i = 0; $i < 2; ++$i) {
        verify($broken->take(1)->all() === [1], 'Failure lost valid prefix.');
        try {
            $broken->all();
            throw new LogicException('Source failure suppressed.');
        } catch (DomainException $caught) {
            verify($caught === $failure, 'Replay replaced source failure.');
        }
    }
    verify($calls === 1, 'Failed one-shot source was restarted.');
    echo "Lazy factory bounded pipeline, take-until and replayed source failure passed\n";
}

function configMergeContracts(): void
{
    $base = ['db' => ['hosts' => ['a', 'b'], 'options' => ['timeout' => 5, 'tls' => true]], 'enabled' => true];
    $overlay = ['db' => ['hosts' => ['c'], 'options' => ['timeout' => 0]], 'enabled' => false];
    $expected = ['db' => ['hosts' => ['c'], 'options' => ['timeout' => 0, 'tls' => true]], 'enabled' => false];
    verify(ConfigMerge::merge($base, $overlay) === $expected && $base['db']['hosts'] === ['a', 'b'], 'Config map/list ownership differs.');
    $layers = (static function () use ($base, $overlay): Generator {
        yield $base;
        yield $overlay;
        yield ['db' => ['hosts' => [], 'options' => ['tls' => null]]];
    })();
    verify(ConfigMerge::mergeMany($layers) === ['db' => ['hosts' => [], 'options' => ['timeout' => 0, 'tls' => null]], 'enabled' => false], 'Iterable overlay lost empty/null values.');
    verify(ConfigMerge::merge(['v' => ['key' => 1]], ['v' => [1, 2]]) === ['v' => [1, 2]] && ConfigMerge::merge(['v' => [1, 2]], ['v' => ['key' => 1]]) === ['v' => ['key' => 1]], 'List/map transition merged incompatible shapes.');
    echo "Config map/list replacement, iterable layers and falsey values passed\n";
}

function configResilienceContracts(): void
{
    $root = evidenceDirectory() . '/config-resilience';
    $source = $root . '/source';
    $cache = $root . '/cache';
    foreach ([$root, $source, $cache] as $dir) {
        verify(mkdir($dir, 0700), 'Config fixture directory unavailable.');
    }
    verify(file_put_contents($source . '/service.php', '<?php return ["generation"=>1,"enabled"=>false];') !== false, 'Source fixture failed.');
    // Use supported legacy flat cache layout to exercise malformed generated namespace directly.
    verify(file_put_contents($cache . '/service.php', '<?php return "invalid";') !== false, 'Cache fixture failed.');
    $strict = new LazyFileConfig($source, namespaceCacheDirectory: $cache);
    expectFailure(static fn() => $strict->get('service'), UnexpectedValueException::class, 'Strict cache accepted malformed namespace.');
    $resilient = new ResilientLazyFileConfig($source, namespaceCacheDirectory: $cache);
    verify($resilient->get('service') === ['generation' => 1, 'enabled' => false], 'Resilient cache did not load valid source.');
    verify(file_put_contents($source . '/service.php', '<?php return ["generation"=>2,"enabled"=>true];') !== false, 'Replacement source failed.');
    verify($resilient->get('service.generation') === 1, 'Loaded namespace changed without invalidation.');
    $resilient->warmNamespaceCache('service');
    $replacement = new ResilientLazyFileConfig($source, namespaceCacheDirectory: $cache);
    verify($replacement->get('service') === ['generation' => 2, 'enabled' => true], 'Rebuilt cache did not publish new generation.');
    verify(file_put_contents($source . '/broken.php', '<?php return false;') !== false, 'Malformed source fixture failed.');
    expectFailure(static fn() => $replacement->get('broken'), UnexpectedValueException::class, 'Malformed authoritative source was concealed.');
    echo "Strict/resilient namespace corruption, source recovery and rebuild passed\n";
}

function uidOrderingContracts(): void
{
    $numeric = ['99999999999999999999', '100000000000000000000', '18446744073709551616', '2', '0002', '0'];
    $expected = ['0', '2', '0002', '18446744073709551616', '99999999999999999999', '100000000000000000000'];
    verify(IdComparator::sort($numeric) === $expected, 'Unsigned numeric ordering or stable equality differs.');
    verify(IdComparator::compare('0002', '2') === 0 && IdComparator::compare('18446744073709551616', '18446744073709551615') > 0, 'Numeric comparison narrowed to native integer.');
    $mixed = ['a', '10', 'A', '2', '01z'];
    verify(IdComparator::sort($mixed) === ['2', '10', '01z', 'A', 'a'], 'Mixed numeric/lexical ordering differs.');
    foreach ($expected as $left) {
        foreach ($expected as $right) {
            verify((IdComparator::compare($left, $right) <=> 0) === -(IdComparator::compare($right, $left) <=> 0), 'Comparator is not antisymmetric.');
        }
    }
    echo "UID oversized decimal, leading zero, mixed lexical and comparator symmetry passed\n";
}
