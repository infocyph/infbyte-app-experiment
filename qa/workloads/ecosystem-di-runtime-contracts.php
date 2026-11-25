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

function diCacheWorkload(): void
{
    $options = new CacheOptions(allowObjects: false, allowClosures: false, failOpen: false);
    foreach (['redis', 'sqlite'] as $backend) {
        $pool = static fn(): Cache => $backend === 'redis'
            ? Cache::redis('di-definition-contract', options: $options)
            : Cache::sqlite('di-definition-contract', evidenceDirectory() . '/di-cache.sqlite', $options);
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
