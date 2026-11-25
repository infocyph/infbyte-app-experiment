<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\InterMix\Exceptions\ContainerException;

final readonly class LabFactoryProduct
{
    public function __construct(public string $tenant, public array $options) {}

    public static function create(string $tenant, array $options): self
    {
        return new self($tenant, $options);
    }
}

function diFactoriesWorkload(): void
{
    $path = evidenceDirectory() . '/factory-container.php';
    $mutable = 7;
    $options = ['limit' => &$mutable, 'active' => false, 'nullable' => null];
    $constructor = FactoryDefinition::construct(LabFactoryProduct::class, [new ServiceReference('tenant'), $options]);
    $static = FactoryDefinition::staticFactory(LabFactoryProduct::class, 'create', [new ServiceReference('tenant'), $options]);
    $mutable = 99;
    $expected = ['limit' => 7, 'active' => false, 'nullable' => null];

    verify($constructor->signature() === [
        'class' => LabFactoryProduct::class, 'method' => null,
        'arguments' => [['service' => 'tenant'], ['value' => $expected]],
    ], 'Constructor recipe snapshot or reference identity changed.');
    verify($static->signature()['method'] === 'create', 'Static method recipe identity changed.');

    $builder = ContainerBuilder::create('factory-contracts')
        ->setEnvironment('production')
        ->value('tenant', 'alpha')
        ->factory('singleton', $constructor, LifetimeEnum::Singleton, ['products'])
        ->factory('transient', $static, LifetimeEnum::Transient)
        ->factory('scoped', $static, LifetimeEnum::Scoped)
        ->alias('product', 'singleton');
    $graph = $builder->definitionGraph();
    verify($graph->environment() === 'production' && $graph->hasDefinition('singleton'), 'Graph does not describe finalized configuration.');
    verify($graph->definitionMetaFor('singleton')['tags'] === ['products'], 'Graph metadata lost tags.');
    verify($graph->definitionMetaFor('scoped')['lifetime'] === LifetimeEnum::Scoped, 'Graph metadata lost scope.');
    expectFailure(static fn() => $builder->value('tenant', 'beta'), ContainerException::class, 'Frozen graph was mutable.');
    $report = $builder->compile($path, strict: true);
    verify(is_string($report['digest']) && hash_file('sha256', $path) === $report['digest'], 'Compiled recipe artifact digest mismatch.');

    foreach ([$builder->build(), $builder->production($path)] as $runtime) {
        $singleton = $runtime->get('singleton');
        verify($singleton === $runtime->get('product') && $singleton === $runtime->get('singleton'), 'Alias or singleton identity failed.');
        verify($singleton->tenant === 'alpha' && $singleton->options === $expected, 'Factory snapshot or service reference failed.');
        $first = $runtime->get('transient');
        $second = $runtime->get('transient');
        verify($first !== $second && $first->options === $expected && $second->tenant === 'alpha', 'Transient static factory failed.');
        $a = $runtime->withinScope('first', static function (RuntimeContainerInterface $scope): LabFactoryProduct {
            $product = $scope->get('scoped');
            verify($product === $scope->get('scoped'), 'Scoped factory resolved more than once.');
            return $product;
        });
        $b = $runtime->withinScope('second', static fn(RuntimeContainerInterface $scope) => $scope->get('scoped'));
        verify($a !== $b && $a->options === $b->options, 'Scoped factory leaked across requests.');
        verify(iterator_to_array($runtime->tagged('products')) === ['singleton' => $singleton], 'Tagged factory identity failed.');
    }

    foreach (LifetimeEnum::cases() as $lifetime) {
        $calls = 0;
        $runtime = ContainerBuilder::create('runtime-factory-' . $lifetime->name)
            ->factory('dynamic', static function (RuntimeContainerInterface $container) use (&$calls): object {
                ++$calls;
                return new stdClass();
            }, $lifetime)
            ->build();
        verify($calls === 0, 'Factory eagerly executed during build.');
        if ($lifetime === LifetimeEnum::Scoped) {
            $pair = $runtime->withinScope('one', static fn(RuntimeContainerInterface $scope): array => [$scope->get('dynamic'), $scope->get('dynamic')]);
            $other = $runtime->withinScope('two', static fn(RuntimeContainerInterface $scope) => $scope->get('dynamic'));
            verify($pair[0] === $pair[1] && $pair[0] !== $other && $calls === 2, 'Dynamic scoped lifetime failed.');
        } else {
            $one = $runtime->get('dynamic');
            $two = $runtime->get('dynamic');
            $singleton = $lifetime === LifetimeEnum::Singleton;
            verify(($one === $two) === $singleton && $calls === ($singleton ? 1 : 2), 'Dynamic lifetime failed.');
        }
    }

    expectFailure(static fn() => FactoryDefinition::construct(LabFactoryProduct::class, ['tenant' => 'bad']), InvalidArgumentException::class, 'Named factory arguments accepted.');
    expectFailure(static fn() => FactoryDefinition::staticFactory(LabFactoryProduct::class, 'missing'), InvalidArgumentException::class, 'Missing factory method accepted.');
    expectFailure(static fn() => FactoryDefinition::construct(LabFactoryProduct::class, [new stdClass()]), InvalidArgumentException::class, 'Opaque factory value accepted.');
    $dynamic = ContainerBuilder::create('strict-dynamic-rejection')->factory('opaque', static fn() => new stdClass());
    expectFailure(static fn() => $dynamic->compile(evidenceDirectory() . '/invalid-factory.php', strict: true), ContainerException::class, 'Strict compiler accepted opaque runtime factory.');

    $preload = evidenceDirectory() . '/factory-preload.php';
    $preloadBuilder = ContainerBuilder::create('preload-contract')
        ->autowire(LabFactoryProduct::class, LabFactoryProduct::class, ['alpha', []]);
    $preloadBuilder->generatePreload($preload);
    $source = file_get_contents($preload);
    verify(is_string($source) && $source !== '', 'Preload artifact missing.');
    token_get_all($source, TOKEN_PARSE);
    expectFailure(static fn() => $preloadBuilder->value('later', true), ContainerException::class, 'Preload did not finalize the graph.');
    echo "Compiled constructor/static factories, frozen graph, all runtime lifetimes, alias/tag ownership, rejected recipes and preload generation passed\n";
}
