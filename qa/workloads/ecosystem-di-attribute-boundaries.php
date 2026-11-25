<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Attribute\AttributeResolution;
use Infocyph\InterMix\DI\Attribute\AttributeResolverInterface;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final readonly class LabBoundaryAttribute
{
    public function __construct(public string $operation) {}
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class LabIgnoredBoundaryAttribute {}

final class LabAttributeState
{
    public array $calls = [];

    public function __construct(public mixed $value = 'value') {}
}

final class LabBoundaryAttributeResolver implements AttributeResolverInterface
{
    public function resolve(object $attributeInstance, Reflector $target, RuntimeContainerInterface $container): mixed
    {
        verify($attributeInstance instanceof LabBoundaryAttribute, 'Unexpected resolver argument.');
        $state = $container->get(LabAttributeState::class);
        verify($state instanceof LabAttributeState, 'Resolver received foreign request state.');
        $state->calls[] = $attributeInstance->operation;

        return match ($attributeInstance->operation) {
            'unresolved' => AttributeResolution::Unresolved,
            'null' => null,
            'value' => $state->value,
            'throw' => throw new DomainException('lab attribute resolver rejected'),
            default => throw new LogicException('Unknown attribute operation.'),
        };
    }
}

final class LabBoundaryPropertyDefaults
{
    #[LabBoundaryAttribute('unresolved')]
    public string $retained = 'initial';

    #[LabBoundaryAttribute('null')]
    public ?string $nullable = 'initial';

    #[LabBoundaryAttribute('unresolved')]
    #[LabBoundaryAttribute('value')]
    public string $ordered = 'initial';
}

final class LabBoundaryPropertyInput
{
    #[LabBoundaryAttribute('value')]
    public string $value = 'initial';
}

final class LabBoundaryMethod
{
    public int $calls = 0;

    public function fallback(#[LabBoundaryAttribute('unresolved')] string $value = 'default'): string
    {
        ++$this->calls;

        return $value;
    }

    public function nullable(#[LabBoundaryAttribute('null')] ?string $value = 'default'): ?string
    {
        ++$this->calls;

        return $value;
    }

    public function value(#[LabBoundaryAttribute('value')] string $value): string
    {
        ++$this->calls;

        return $value;
    }

    public function throwing(#[LabBoundaryAttribute('throw')] string $value): string
    {
        ++$this->calls;

        return $value;
    }

    public function ignored(#[LabIgnoredBoundaryAttribute] string $value = 'default'): string
    {
        ++$this->calls;

        return $value;
    }
}

final class LabBoundaryConstructor
{
    public readonly string $value;

    public function __construct(#[LabBoundaryAttribute('throw')] string $value = 'constructor-default')
    {
        $this->value = $value;
    }
}

/** Assert the root contract even when the container wraps the original exception. */
function labAttributeFailure(callable $operation, string $expected, string $message): void
{
    try {
        $operation();
    } catch (Throwable $failure) {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof $expected) {
                return;
            }
        }
        throw $failure;
    }

    throw new RuntimeException($message);
}

function labAttributeRuntime(string $mode, bool $checkRegistration): RuntimeContainerInterface
{
    $builder = ContainerBuilder::create('lab-attribute-boundaries')
        ->enablePropertyAttributes()->enableMethodAttributes()
        ->registerAttributeResolver(LabBoundaryAttribute::class, LabBoundaryAttributeResolver::class)
        ->input(LabAttributeState::class)
        ->autowire(LabBoundaryPropertyDefaults::class, LabBoundaryPropertyDefaults::class, lifetime: LifetimeEnum::Scoped)
        ->autowire(LabBoundaryPropertyInput::class, LabBoundaryPropertyInput::class, lifetime: LifetimeEnum::Scoped)
        ->autowire(LabBoundaryMethod::class, LabBoundaryMethod::class, lifetime: LifetimeEnum::Scoped)
        ->autowire(LabBoundaryConstructor::class, LabBoundaryConstructor::class, lifetime: LifetimeEnum::Scoped);
    if ($checkRegistration) {
        expectFailure(static fn() => $builder->registerAttributeResolver(LabBoundaryAttribute::class, stdClass::class),
            ContainerException::class, 'Non-resolver registered.');
        expectFailure(static fn() => $builder->registerAttributeResolver('LabMissingAttributeType', LabBoundaryAttributeResolver::class),
            ContainerException::class, 'Missing attribute class registered.');
        $builder->registerAttributeResolver(LabBoundaryAttribute::class, LabBoundaryAttributeResolver::class);
    }
    if ($mode === 'dynamic') {
        return $builder->build();
    }
    verify($mode === 'compiled', 'Unexpected container mode.');
    $path = evidenceDirectory() . '/custom-attribute-boundaries.php';
    $report = $builder->compile($path, strict: true);
    verify($report['skipped'] === [], 'Custom attribute graph fell back outside strict compilation.');

    return $builder->productionPrevalidated($path, $report['digest']);
}

function diAttributeBoundaryContracts(string $mode, string $contract): void
{
    $runtime = labAttributeRuntime($mode, $contract === 'registration');
    $state = new LabAttributeState('tenant-alpha');
    $seed = [LabAttributeState::class => $state];
    if ($contract === 'property-type') {
        $invalid = new LabAttributeState(['not a string']);
        labAttributeFailure(static fn() => $runtime->withinScope('request',
            static fn(RuntimeContainerInterface $scope) => $scope->get(LabBoundaryPropertyInput::class),
            [LabAttributeState::class => $invalid]), TypeError::class, 'Invalid property assignment accepted.');
        verify($invalid->calls === ['value'], 'Rejected property was not resolved exactly once.');
        expectFailure($runtime->captureScopeContext(...), ContainerException::class, 'Failed property retained request scope.');
        $valid = $runtime->withinScope('request', static fn(RuntimeContainerInterface $scope) => $scope->get(LabBoundaryPropertyInput::class), $seed);
        verify($valid->value === 'tenant-alpha' && $state->calls === ['value'], 'Failed property poisoned replacement scope.');
    } elseif ($contract === 'missing-input') {
        labAttributeFailure(static fn() => $runtime->withinScope('request',
            static fn(RuntimeContainerInterface $scope) => $scope->get(LabBoundaryPropertyInput::class)),
            ContainerException::class, 'Attribute resolved missing request input.');
        expectFailure($runtime->captureScopeContext(...), ContainerException::class, 'Missing attribute state retained scope.');
        $valid = $runtime->withinScope('request', static fn(RuntimeContainerInterface $scope) => $scope->get(LabBoundaryPropertyInput::class), $seed);
        verify($valid->value === 'tenant-alpha', 'Missing input poisoned replacement request.');
    } else {
        $runtime->withinScope('request', static function (RuntimeContainerInterface $scope) use ($state, $contract): void {
            if (in_array($contract, ['property-defaults', 'registration'], true)) {
                $service = $scope->get(LabBoundaryPropertyDefaults::class);
                verify($service->retained === 'initial' && $service->nullable === null && $service->ordered === 'tenant-alpha',
                    'Unresolved/null/ordered property values diverged.');
                verify($state->calls === ['unresolved', 'null', 'unresolved', 'value'], 'Property resolver order changed.');
                verify($scope->get(LabBoundaryPropertyDefaults::class) === $service && count($state->calls) === 4, 'Scoped property re-resolution occurred.');
                return;
            }
            if ($contract === 'constructor') {
                verify($scope->get(LabBoundaryConstructor::class)->value === 'constructor-default' && $state->calls === [],
                    'Method-only custom attribute ran on constructor.');
                return;
            }
            $service = $scope->get(LabBoundaryMethod::class);
            switch ($contract) {
                case 'method-fallback':
                    verify($scope->invoke([$service, 'fallback']) === 'default', 'Unresolved method default lost.');
                    verify($scope->invoke([$service, 'nullable']) === null, 'Resolved null treated as unresolved.');
                    verify($scope->invoke([$service, 'ignored']) === 'default', 'Unregistered attribute changed invocation.');
                    verify($service->calls === 3 && $state->calls === ['unresolved', 'null'], 'Method default or resolver count differs.');
                    break;
                case 'method-override':
                    verify($scope->invoke([$service, 'throwing'], ['value' => 'explicit']) === 'explicit', 'Explicit argument did not take precedence.');
                    verify($state->calls === [] && $service->calls === 1, 'Overridden resolver executed.');
                    verify($scope->invoke([$service, 'value']) === 'tenant-alpha' && $state->calls === ['value'], 'Override leaked to later invocation.');
                    break;
                case 'method-throw':
                    labAttributeFailure(static fn() => $scope->invoke([$service, 'throwing']), DomainException::class, 'Resolver exception concealed.');
                    verify($service->calls === 0 && $state->calls === ['throw'], 'Failed resolver reached business method.');
                    verify($scope->invoke([$service, 'value']) === 'tenant-alpha' && $service->calls === 1, 'Resolver exception damaged subsequent invocation.');
                    break;
                default:
                    throw new InvalidArgumentException('Unknown attribute boundary.');
            }
        }, $seed);
    }
    expectFailure($runtime->captureScopeContext(...), ContainerException::class, 'Attribute workload leaked request scope.');
    echo "Custom attribute $mode $contract passed\n";
}
