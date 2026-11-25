<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\InterMix\DI\Attribute\AttributeResolverInterface;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;

function diCacheWorkload(?string $selectedBackend = null): void
{
    $options = new CacheOptions(allowObjects: false, allowClosures: false, failOpen: false);
    foreach ($selectedBackend === null ? ['redis', 'sqlite'] : [$selectedBackend] as $backend) {
        $root = evidenceDirectory() . '/di-cache-parity';
        if ($selectedBackend !== null) {
            verify(mkdir($root, 0700), 'DI cache root unavailable.');
        }
        $pool = static fn(): Cache => $selectedBackend !== null
            ? sharedAtomicCache($backend, 'di-definition-contract', $root, $options)
            : ($backend === 'redis'
                ? Cache::redis('di-definition-contract', options: $options)
                : Cache::sqlite('di-definition-contract', evidenceDirectory() . '/di-cache.sqlite', $options));
        verify($pool()->set('unrelated', 'preserve'), 'Independent cache sentinel unavailable.');
        $build = static function (string $generation, ?string $environment, string $value, int &$calls) use ($pool): ContainerBuilder {
            $builder = ContainerBuilder::create('di-contract-' . bin2hex(random_bytes(4)))
                ->definitionCache($pool(), namespace: 'lab-definition', generation: $generation, failOpen: false)
                ->factory('settings', static function () use ($value, &$calls): array {
                    ++$calls;
                    return ['value' => $value, 'nullable' => null, 'enabled' => false];
                })->cacheDefinition('settings')
                ->factory('nullable', static fn() => null)->cacheDefinition('nullable')
                ->factory('object', static fn() => new stdClass())->cacheDefinition('object')
                ->factory('local', static fn() => $value)
                ->factory('transient', static fn() => new stdClass(), LifetimeEnum::Transient);
            if ($environment !== null) {
                $builder->setEnvironment($environment);
            }
            return $builder;
        };
        $firstCalls = 0;
        $first = $build('release-a', 'production', 'first', $firstCalls);
        $report = $first->warmDefinitionCache();
        verify($report['written'] === 2 && $report['hits'] === 0 && $report['failed'] === 0 && $firstCalls === 1, 'Definition cache warmup did not persist exactly eligible safe values.');
        $replacementCalls = 0;
        $replacement = $build('release-a', 'production', 'second', $replacementCalls);
        $report = $replacement->warmDefinitionCache();
        verify($report['hits'] === 2 && $report['written'] === 0 && $report['failed'] === 0 && $replacementCalls === 0, 'Replacement warmup missed persisted generation.');
        $runtime = $replacement->build();
        verify($runtime->get('settings') === ['value' => 'first', 'nullable' => null, 'enabled' => false]
            && $runtime->get('nullable') === null && $replacementCalls === 0, 'Fresh runtime did not reuse safe persistent values.');
        verify($runtime->get('local') === 'second' && $runtime->get('object') instanceof stdClass && $runtime->get('transient') !== $runtime->get('transient'), 'Persistence changed ineligible lifetimes.');
        foreach ([
            ['release-a', 'staging', 'staging'],
            ['release-b', 'production', 'new-release'],
            ['release-a', null, 'unset'],
            ['release-a', 'default', 'literal-default'],
        ] as [$generation, $environment, $value]) {
            $calls = 0;
            $next = $build($generation, $environment, $value, $calls)->build();
            verify($next->get('settings')['value'] === $value && $calls === 1, 'Environment or release cache identity collided.');
        }
        $calls = 0;
        $old = $build('release-a', 'production', 'unused', $calls)->build();
        verify($old->get('settings')['value'] === 'first' && $calls === 0 && $pool()->get('unrelated') === 'preserve', 'Generation replacement altered old or unrelated entries.');
        echo "$backend explicit cache eligibility, warmup, safe values, generations and environments passed\n";
    }
    $invalid = ContainerBuilder::create('invalid-cache-eligibility')
        ->definitionCache(Cache::memory(), 'invalid', 'release')
        ->factory('transient', static fn() => 42, LifetimeEnum::Transient)->cacheDefinition('transient');
    expectFailure(static fn() => $invalid->build(), ContainerException::class, 'Transient definition admitted into persistent cache.');
}

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class LabRuntimeAttribute
{
    public function __construct(public string $id) {}
}

final class LabRuntimeAttributeResolver implements AttributeResolverInterface
{
    public function resolve(object $attributeInstance, Reflector $target, RuntimeContainerInterface $container): mixed
    {
        verify($attributeInstance instanceof LabRuntimeAttribute, 'Unexpected attribute passed to resolver.');
        return $container->get($attributeInstance->id);
    }
}

final class LabAttributeDependency {}

final class LabAttributeService
{
    #[LabRuntimeAttribute('tenant')]
    public string $tenant = 'initial';

    #[LabRuntimeAttribute('nullable')]
    public ?string $nullable = 'initial';

    public function __construct(public LabAttributeDependency $dependency) {}

    public function receipt(#[LabRuntimeAttribute('tenant')] string $tenant, int $quantity = 1): array
    {
        return [$tenant, $quantity];
    }
}

function diAttributesWorkload(): void
{
    $builder = ContainerBuilder::create('attribute-trace-contract')
        ->enablePropertyAttributes()->enableMethodAttributes()
        ->registerAttributeResolver(LabRuntimeAttribute::class, LabRuntimeAttributeResolver::class)
        ->value('tenant', 'alpha')->value('nullable', null)
        ->autowire(LabAttributeDependency::class, LabAttributeDependency::class)
        ->autowire(LabAttributeService::class, LabAttributeService::class)
        ->enableDebugTracing(true, TraceLevelEnum::Verbose);
    $path = evidenceDirectory() . '/attribute-runtime.php';
    $report = $builder->compile($path);
    foreach ([$builder->build(), $builder->productionPrevalidated($path, $report['digest'])] as $runtime) {
        $service = $runtime->get(LabAttributeService::class);
        verify($service->tenant === 'alpha' && $service->nullable === null && $service->dependency === $runtime->get(LabAttributeDependency::class), 'Runtime attribute/property or dependency identity failed.');
        verify($runtime->invoke([$service, 'receipt'], ['tenant' => 'override', 'quantity' => 2]) === ['override', 2], 'Explicit invocation arguments lost precedence.');
    }
    $graph = $builder->exportGraph(LabAttributeService::class);
    verify(in_array(LabAttributeService::class, $graph['nodes'], true) && in_array(LabAttributeDependency::class, $graph['nodes'], true), 'Resolved dependency graph missing nodes.');
    verify(array_any($graph['edges'], static fn(array $edge): bool => $edge['from'] === LabAttributeService::class && $edge['to'] === LabAttributeDependency::class && $edge['count'] >= 1), 'Resolved dependency graph missing edge.');
    $runtime = $builder->build();
    $entries = $runtime->debug(LabAttributeService::class);
    verify($entries !== [] && $runtime->tracer()->getEntries() === [], 'Trace consumption did not clear buffer.');
    $quiet = ContainerBuilder::create('quiet-trace')
        ->autowire(LabAttributeDependency::class, LabAttributeDependency::class)->build();
    $quiet->get(LabAttributeDependency::class);
    verify(!$quiet->tracer()->isEnabled() && $quiet->tracer()->getEntries() === [], 'Tracing enabled by default.');
    echo "Dynamic/compiled custom property attributes, explicit invocation, dependency graph and trace lifecycle passed\n";
}

function diCacheOutageWorkload(): void
{
    $root = (string) getenv('LAB_CACHE_FAULT_ROOT');
    $dsn = 'redis://127.0.0.1:17389';
    $pool = static fn(): Cache => Cache::redis('di-outage', $dsn, options: new CacheOptions(failOpen: false));
    $builder = static function (Cache $cache, string $generation, bool $failOpen, string $value, int &$calls): ContainerBuilder {
        return ContainerBuilder::create('outage-' . bin2hex(random_bytes(4)))
            ->definitionCache($cache, 'di-outage', $generation, $failOpen)
            ->factory('settings', static function () use ($value, &$calls): array {
                ++$calls;
                return ['value' => $value];
            })->cacheDefinition('settings');
    };
    $seedCalls = 0;
    $cache = $pool();
    verify($cache->set('unrelated', 42, 600), 'Cache sentinel unavailable.');
    $seed = $builder($cache, 'stable', false, 'durable', $seedCalls)->build();
    verify($seed->get('settings') === ['value' => 'durable'] && $seedCalls === 1, 'Initial definition not persisted.');
    $closedCalls = 0;
    $openCalls = 0;
    $newCalls = 0;
    $closed = $builder($pool(), 'stable', false, 'must-not-run', $closedCalls)->build();
    $open = $builder($pool(), 'stable', true, 'local-fallback', $openCalls)->build();
    $new = $builder($pool(), 'offline-generation', true, 'offline-only', $newCalls)->build();
    verify(touch($root . '/seeded'), 'Definition cache readiness marker failed.');
    cacheWaitMarker($root . '/stopped');
    expectFailure(static fn() => $closed->get('settings'), ContainerException::class, 'Strict definition resolution concealed cache outage.');
    verify($closedCalls === 0, 'Strict resolution executed factory after cache read failure.');
    verify($open->get('settings') === ['value' => 'local-fallback'] && $open->get('settings') === ['value' => 'local-fallback'] && $openCalls === 1, 'Fail-open singleton fallback or ownership failed.');
    verify($new->get('settings') === ['value' => 'offline-only'] && $newCalls === 1, 'New generation could not resolve during outage.');
    verify(touch($root . '/observed'), 'Definition cache outage marker failed.');
    cacheWaitMarker($root . '/restarted');
    $freshCalls = 0;
    $recoveredPool = $pool();
    $fresh = $builder($recoveredPool, 'stable', false, 'unexpected', $freshCalls)->build();
    verify($fresh->get('settings') === ['value' => 'durable'] && $freshCalls === 0, 'Outage fallback overwrote durable definition.');
    verify($open->get('settings') === ['value' => 'local-fallback'] && $openCalls === 1, 'Existing singleton changed after recovery.');
    $recoveryCalls = 0;
    $replacement = $builder($recoveredPool, 'offline-generation', false, 'recovered', $recoveryCalls)->build();
    verify($replacement->get('settings') === ['value' => 'recovered'] && $recoveryCalls === 1, 'Failed outage write leaked into new generation.');
    $lastCalls = 0;
    verify($builder($pool(), 'offline-generation', false, 'unused', $lastCalls)->build()->get('settings') === ['value' => 'recovered'] && $lastCalls === 0, 'Recovered definition was not persisted.');
    verify($recoveredPool->get('unrelated') === 42, 'Definition recovery changed unrelated key.');
    echo "Real Redis outage, strict/fail-open definition policy, singleton ownership and durable generation recovery passed\n";
}
