<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Collection\LazyCollection;
use Infocyph\ArrayKit\Collection\HookedCollection;
use Infocyph\ArrayKit\Config\LazyFileConfig;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\UID\Id;
use Infocyph\UID\UUID;
use Infocyph\UID\ULID;
use Infocyph\UID\Support\BaseEncoder;

final readonly class LabExecution
{
    public function __construct(public int $id) {}
}

final readonly class LabScopedService
{
    public function __construct(public LabExecution $execution) {}
}

final class LabSingleton {}
final class LabTransient {}

function intermixWorkload(): void
{
    $builder = ContainerBuilder::create('production-lab')
        ->input(LabExecution::class)
        ->autowire(LabScopedService::class, LabScopedService::class, lifetime: LifetimeEnum::Scoped)
        ->autowire(LabSingleton::class, LabSingleton::class, lifetime: LifetimeEnum::Singleton)
        ->autowire(LabTransient::class, LabTransient::class, lifetime: LifetimeEnum::Transient)
        ->alias('execution-service', LabScopedService::class);
    $path = evidenceDirectory() . '/intermix-build/container.php';
    mkdir(dirname($path), 0700, true);
    $builder->compile($path, strict: true);
    foreach ([$builder->build(), $builder->production($path)] as $runtime) {
        $singleton = $runtime->get(LabSingleton::class);
        for ($id = 1; $id <= 1000; ++$id) {
            $runtime->withinScope('job', static function ($active) use ($id, $singleton): void {
                $scoped = $active->get(LabScopedService::class);
                verify($scoped->execution->id === $id && $active->get('execution-service') === $scoped, 'Alias or scoped identity mismatch.');
                verify($active->get(LabSingleton::class) === $singleton, 'Singleton identity changed.');
                verify($active->get(LabTransient::class) !== $active->get(LabTransient::class), 'Transient was cached.');
                $context = $active->captureScopeContext();
                expectFailure(
                    static fn() => $active->withinScopeContext($context, static fn($borrowed) => $borrowed->get(LabScopedService::class)),
                    \Infocyph\InterMix\Exceptions\ContainerException::class,
                    'Same-carrier scope replacement was accepted.',
                );
                $child = new Fiber(static fn() => $active->withinScopeContext(
                    $context,
                    static fn($borrowed) => $borrowed->get(LabScopedService::class),
                ));
                $child->start();
                verify($child->getReturn() === $scoped, 'Borrowed scope identity mismatch.');
                $active->withinScope('nested', static function ($nested) use ($id, $scoped): void {
                    $service = $nested->get(LabScopedService::class);
                    verify($service !== $scoped && $service->execution->id === -$id, 'Nested scope inherited owner input.');
                }, [LabExecution::class => new LabExecution(-$id)]);
                verify($active->get(LabScopedService::class) === $scoped, 'Owner scope not restored.');
                verify($active->invoke(static fn(LabScopedService $service, int $zero): int => $service->execution->id + $zero, ['zero' => 0]) === $id, 'Typed invocation failed.');
            }, [LabExecution::class => new LabExecution($id)]);
        }
    }
    echo "Dynamic/compiled DI: 2000 scoped jobs, nested/borrowed scopes, lifetimes, aliases and invocation passed\n";
}

function arrayConfigWorkload(): void
{
    $root = evidenceDirectory() . '/arraykit';
    mkdir($root . '/source', 0700, true);
    mkdir($root . '/cache', 0700, true);
    $db = $root . '/source/db.php';
    $queue = $root . '/source/queue.php';
    file_put_contents($db, "<?php return ['port' => 5432, 'nested' => ['active' => true]];", LOCK_EX);
    file_put_contents($queue, "<?php return ['workers' => 4];", LOCK_EX);
    $publisher = new LazyFileConfig($root . '/source', namespaceCacheDirectory: $root . '/cache');
    $publisher->warmNamespaceCache(['db', 'queue']);
    $old = new LazyFileConfig($root . '/source', namespaceCacheDirectory: $root . '/cache');
    verify($old->get('db.port') === 5432, 'Initial generation missing.');
    file_put_contents($db, "<?php return ['port' => 6432, 'nested' => ['active' => false]];", LOCK_EX);
    $publisher->warmNamespaceCache(['db']);
    $fresh = new LazyFileConfig($root . '/source', namespaceCacheDirectory: $root . '/cache');
    verify($fresh->get('db.port') === 6432 && $fresh->get('queue.workers') === 4, 'Partial rebuild lost unchanged namespace.');
    verify($old->get('db.port') === 5432 && $old->get('db.nested.active') === true, 'Existing worker generation changed.');
    verify($fresh->get('db.nested.active') === false, 'Flat/structural generation mismatch.');
    file_put_contents($db, '<?php return false;', LOCK_EX);
    try {
        $publisher->warmNamespaceCache(['db']);
    } catch (Exception) {
        // Invalid authoritative source must not replace active generation.
    }
    $recovered = new LazyFileConfig($root . '/source', namespaceCacheDirectory: $root . '/cache');
    verify($recovered->get('db.port') === 6432 && $recovered->get('queue.workers') === 4, 'Failed publication replaced last valid generation.');
    echo "Lazy config publication, pinning, partial rebuild and failed generation recovery passed\n";
}

function arrayStreamWorkload(): void
{
    $source = LazyCollection::fromFactory(static function (): Generator {
        for ($i = 1; $i <= 100000; ++$i) {
            yield $i;
        }
    });
    $processed = $source->filterLazy(static fn(int $id): bool => $id % 2 === 0)->mapLazy(static fn(int $id): int => $id * 2);
    $initial = memory_get_usage(true);
    for ($pass = 0; $pass < 3; ++$pass) {
        $count = 0;
        $sum = 0;
        foreach ($processed->chunkLazy(137) as $batch) {
            foreach ($batch as $value) {
                ++$count;
                $sum += $value;
            }
        }
        verify($count === 50000 && $sum === 5000100000, 'Renewable lazy pipeline lost or duplicated data.');
    }
    verify(memory_get_usage(true) - $initial <= 16 * 1024 * 1024, 'Renewable stream retained unbounded data.');
    $hooked = new HookedCollection(['name' => 'alice']);
    $hooked->onGet('name', static fn(string $value): string => strtoupper($value));
    $copy = $hooked->copy();
    $copy->set('name', 'bob');
    verify($hooked['name'] === 'ALICE' && $copy['name'] === 'BOB', 'Hooked collection copy leaked data or hooks.');
    echo "300k renewable input rows and isolated hooked collection passed\n";
}

function uidFormatsWorkload(): void
{
    $versions = [UUID::v1(), UUID::v2(1001), UUID::v3('dns', 'example.test'), UUID::v4(), UUID::v5('dns', 'example.test'), UUID::v6(), UUID::v7(), UUID::v8()];
    foreach ($versions as $i => $uuid) {
        verify(UUID::isValid($uuid) && UUID::parse($uuid)['version'] === $i + 1, 'UUID version/variant mismatch.');
        verify(UUID::fromBytes(UUID::toBytes($uuid)) === $uuid, 'UUID binary roundtrip failed.');
        foreach ([10, 16, 32, 36, 58, 62] as $base) {
            verify(UUID::fromBase(UUID::toBase($uuid, $base), $base) === $uuid, 'UUID base conversion failed.');
        }
    }
    verify(UUID::v5('dns', 'example.test') === $versions[4], 'Namespace ID not deterministic.');
    verify(UUID::v5('dns', 'other.test') !== $versions[4], 'Namespace ID collision.');
    verify(UUID::isNil(UUID::nil()) && UUID::isMax(UUID::max()) && !UUID::isValid('invalid'), 'UUID boundary validity failed.');
    $seen = [];
    for ($i = 0; $i < 2000; ++$i) {
        $uuid = Id::uuid();
        $ulid = Id::ulid();
        verify(!isset($seen[$uuid], $seen[$ulid]) && !isset($seen[$uuid]) && !isset($seen[$ulid]), 'Generated identifier collision.');
        $seen[$uuid] = $seen[$ulid] = true;
        verify(ULID::fromBase(ULID::toBase($ulid, 62), 62) === $ulid, 'ULID encoding mismatch.');
    }
    $bytes = "\0\0" . random_bytes(14);
    foreach ([10, 16, 32, 36, 58, 62] as $base) {
        verify(BaseEncoder::decodeToBytes(BaseEncoder::encodeBytes($bytes, $base), $base, 16) === $bytes, 'Leading zero binary encoding failed.');
    }
    echo "UUID versions 1–8, six base encodings and 4000 unique generated identifiers passed\n";
}
