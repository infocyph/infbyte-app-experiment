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

final readonly class LabRequestInput
{
    public function __construct(public string $tenant) {}
}

final readonly class LabScopedProjection
{
    public function __construct(public LabRequestInput $input) {}
}

function diOwnershipRuntime(string $mode): RuntimeContainerInterface
{
    $builder = ContainerBuilder::create('lab-di-ownership')
        ->input(LabRequestInput::class)->input('nullable')
        ->alias('request.input', LabRequestInput::class)
        ->autowire(LabScopedProjection::class, LabScopedProjection::class, lifetime: LifetimeEnum::Scoped, tags: ['projection'])
        ->autowire(LabGraphScoped::class, LabGraphScoped::class, lifetime: LifetimeEnum::Scoped)
        ->value('stable', 'stable');
    if ($mode === 'dynamic') {
        return $builder->build();
    }
    $path = evidenceDirectory() . '/ownership-' . bin2hex(random_bytes(8)) . '.php';
    $report = $builder->compile($path, strict: true);
    verify($report['skipped'] === [], 'Strict input/scope graph retained fallback entries.');
    return $builder->productionPrevalidated($path, $report['digest']);
}

function diOwnershipContracts(string $mode, string $contract): void
{
    $runtime = diOwnershipRuntime($mode);
    $input = new LabRequestInput('alpha');
    $seed = [LabRequestInput::class => $input, 'nullable' => null];
    $get = static fn(RuntimeContainerInterface $scope): LabScopedProjection => $scope->get(LabScopedProjection::class);
    switch ($contract) {
        case 'input-identity':
            $first = $runtime->withinScope('request', static function (RuntimeContainerInterface $scope) use ($input, $get): LabScopedProjection {
                $first = $get($scope);
                verify($first === $get($scope) && $first->input === $input && $scope->get('request.input') === $input, 'Scoped input/alias identity differs.');
                verify($scope->get('nullable') === null && $scope->get('stable') === 'stable', 'Nullable input or stable definition differs.');
                return $first;
            }, $seed);
            $secondInput = new LabRequestInput('beta');
            $second = $runtime->withinScope('request', $get, [LabRequestInput::class => $secondInput]);
            verify($first !== $second && $second->input === $secondInput, 'Request input leaked to replacement.');
            break;
        case 'input-rejection':
            expectFailure(static fn() => $runtime->withinScope('request', $get), ContainerException::class, 'Missing request input accepted.');
            expectFailure(static fn() => $runtime->withinScope('request', static fn() => null, ['request.input' => $input]), ContainerException::class, 'Alias seed accepted.');
            expectFailure(static fn() => $runtime->withinScope('request', static fn() => null, ['stable' => 'overwritten']), ContainerException::class, 'Singleton seed accepted.');
            verify($runtime->withinScope('request', $get, $seed)->input === $input && $runtime->get('stable') === 'stable', 'Rejected seed damaged subsequent request.');
            break;
        case 'make-invoke':
            $runtime->withinScope('request', static function (RuntimeContainerInterface $scope) use ($input, $get): void {
                $cached = $get($scope);
                $fresh = $scope->make(LabScopedProjection::class);
                verify($fresh !== $cached && $fresh->input === $input && $get($scope) === $cached, 'Fresh construction changed scoped ownership.');
                $call = static fn(LabRequestInput $input, int $amount = 3): array => [$input, $amount];
                verify($scope->invoke($call) === [$input, 3], 'Scoped callable argument resolution differs.');
                $override = new LabRequestInput('explicit');
                verify($scope->invoke($call, ['input' => $override, 'amount' => 0]) === [$override, 0], 'Explicit invocation override lost.');
            }, $seed);
            break;
        case 'fiber-isolation':
            $first = new Fiber(static fn() => $runtime->withinScope('request', static function (RuntimeContainerInterface $scope) use ($get): LabScopedProjection {
                $projection = $get($scope);
                Fiber::suspend($projection);
                verify($get($scope) === $projection, 'First carrier lost its scope.');
                return $projection;
            }, $seed));
            $secondInput = new LabRequestInput('beta');
            $second = new Fiber(static fn() => $runtime->withinScope('request', static function (RuntimeContainerInterface $scope) use ($get): LabScopedProjection {
                $projection = $get($scope);
                Fiber::suspend($projection);
                verify($get($scope) === $projection, 'Second carrier lost its scope.');
                return $projection;
            }, [LabRequestInput::class => $secondInput]));
            $a = $first->start();
            $b = $second->start();
            verify($a !== $b && $a->input === $input && $b->input === $secondInput, 'Concurrent carriers shared request state.');
            $second->resume();
            $first->resume();
            verify($first->getReturn() === $a && $second->getReturn() === $b, 'Carrier completion changed ownership.');
            break;
        case 'fiber-attachment':
            $runtime->withinScope('request', static function (RuntimeContainerInterface $scope) use ($get): void {
                $parent = $get($scope);
                $context = $scope->captureScopeContext();
                $child = static fn() => $scope->withinScopeContext($context, static function (RuntimeContainerInterface $active) use ($get): LabScopedProjection {
                    $projection = $get($active);
                    Fiber::suspend($projection);
                    return $get($active);
                });
                $a = new Fiber($child);
                $b = new Fiber($child);
                verify($a->start() === $parent && $b->start() === $parent, 'Explicit attachments did not share owner identity.');
                $b->resume();
                $a->resume();
                verify($a->getReturn() === $parent && $b->getReturn() === $parent && $get($scope) === $parent, 'Sibling detach invalidated owner.');
            }, $seed);
            break;
        case 'context-rejection':
            expectFailure($runtime->captureScopeContext(...), ContainerException::class, 'Capture outside scope accepted.');
            $context = $runtime->withinScope('request', static fn(RuntimeContainerInterface $scope) => $scope->captureScopeContext(), $seed);
            expectFailure(static fn() => $runtime->withinScopeContext($context, $get), ContainerException::class, 'Expired owner context accepted.');
            $runtime->withinScope('request', static function (RuntimeContainerInterface $scope) use ($mode, $get): void {
                $context = $scope->captureScopeContext();
                $foreign = diOwnershipRuntime($mode);
                expectFailure(static fn() => $foreign->withinScopeContext($context, $get), ContainerException::class, 'Foreign container accepted scope context.');
                expectFailure(static fn() => serialize($context), ContainerException::class, 'Scope handle could be serialized.');
                verify($get($scope) === $get($scope), 'Rejected transfer changed owner.');
            }, $seed);
            break;
        case 'attachment-failure':
            $runtime->withinScope('request', static function (RuntimeContainerInterface $scope) use ($get): void {
                $parent = $get($scope);
                $context = $scope->captureScopeContext();
                $fiber = new Fiber(static function () use ($scope, $context, $get): LabScopedProjection {
                    expectFailure(static fn() => $scope->withinScopeContext($context, static function (RuntimeContainerInterface $active) use ($get): never {
                        $get($active);
                        throw new DomainException('intentional attachment failure');
                    }), DomainException::class, 'Child failure swallowed.');
                    return $scope->withinScope('independent', $get, [LabRequestInput::class => new LabRequestInput('child')]);
                });
                $fiber->start();
                $fresh = $fiber->getReturn();
                verify($fresh !== $parent && $fresh->input->tenant === 'child' && $get($scope) === $parent, 'Failed child retained attached state or closed owner.');
            }, $seed);
            break;
        case 'tagged-ownership':
            $runtime->withinScope('request', static function (RuntimeContainerInterface $scope) use ($get): void {
                $expected = $get($scope);
                $items = iterator_to_array($scope->tagged('projection'));
                verify($items === [LabScopedProjection::class => $expected], 'Tagged entries lost scoped identity.');
                verify(iterator_to_array($scope->tagged('projection')) === $items, 'Repeated tagged resolution changed instance.');
            }, $seed);
            $iterator = $runtime->withinScope('request', static fn(RuntimeContainerInterface $scope) => $scope->tagged('projection'), $seed);
            expectFailure(static fn() => iterator_to_array($iterator), ContainerException::class, 'Escaped tagged iterator reused closed scope.');
            break;
        case 'failed-request':
            expectFailure(static fn() => $runtime->withinScope('request', static function (RuntimeContainerInterface $scope) use ($get): never {
                $get($scope);
                throw new DomainException('intentional request failure');
            }, $seed), DomainException::class, 'Request failure swallowed.');
            expectFailure($runtime->captureScopeContext(...), ContainerException::class, 'Failed request retained active scope.');
            $replacement = new LabRequestInput('replacement');
            verify($runtime->withinScope('request', $get, [LabRequestInput::class => $replacement])->input === $replacement, 'Failed request contaminated replacement.');
            break;
        default:
            throw new InvalidArgumentException('Unknown DI ownership contract.');
    }
    echo "InterMix $mode/$contract ownership assertions completed\n";
}
