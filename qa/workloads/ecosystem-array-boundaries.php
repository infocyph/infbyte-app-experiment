<?php

declare(strict_types=1);

use Infocyph\ArrayKit\ArrayKit;

function arrayHookOrderWorkload(): void
{
    $collection = ArrayKit::hookedCollection(['order' => ['amount' => 10], 'nil' => null]);
    $calls = [];
    $add = static function (int $value) use (&$calls): int {
        $calls[] = ['add', $value];
        return $value + 2;
    };
    $collection->onSet('order.amount', $add)->onSet('order.amount', $add)
        ->onSet('order.amount', static function (int $value) use (&$calls): int {
            $calls[] = ['multiply', $value];
            return $value * 3;
        });
    $collection->onGet('order.amount', static fn(int $value): int => $value - 1)
        ->onGet('order.amount', static fn(int $value): int => $value * 2);
    $collection['order.amount'] = 4;
    verify($calls === [['add', 4], ['multiply', 6]], 'Set hooks duplicated or reordered.');
    verify($collection->all() === ['order' => ['amount' => 18], 'nil' => null], 'Get hooks altered stored values.');
    verify($collection['order.amount'] === 34 && $collection['order.amount'] === 34, 'Get hook order or repeated read changed.');
    $collection->onGet('nil', static fn(mixed $value): string => $value === null ? 'present' : 'wrong');
    verify($collection['nil'] === 'present' && $collection['missing'] === null, 'Missing and present null hook boundaries diverged.');
    echo "Ordered deduplicated set hooks and non-mutating nested reads passed\n";
}

function arrayHookRejectionWorkload(): void
{
    $collection = ArrayKit::hookedCollection(['order' => ['amount' => 50], 'other' => 7]);
    $collection->onSet('order.amount', static fn(int $value): int => $value * 2);
    $collection->onSet('order.amount', static function (int $value): int {
        if ($value < 0) {
            throw new DomainException('Negative order amount');
        }
        return $value;
    });
    expectFailure(static function () use ($collection): void {
        $collection['order.amount'] = -2;
    }, DomainException::class, 'Rejected write was accepted.');
    verify($collection->all() === ['order' => ['amount' => 50], 'other' => 7], 'Rejected hook partially published data.');
    $collection['order.amount'] = 8;
    verify($collection->all() === ['order' => ['amount' => 16], 'other' => 7], 'Valid write after rejection did not recover.');
    $collection->onGet('other', static fn() => throw new DomainException('Unavailable projection'));
    expectFailure(static fn() => $collection['other'], DomainException::class, 'Read hook failure was concealed.');
    verify($collection->all()['other'] === 7 && $collection['order.amount'] === 16, 'Failed read affected unrelated state.');
    echo "Hook rejection preserved prior data and subsequent operations recovered\n";
}

function arrayHookCopyWorkload(): void
{
    $source = ArrayKit::hookedCollection(['tenant' => ['name' => 'alpha'], 'count' => 1]);
    $source->onGet('tenant.name', static fn(string $name): string => strtoupper($name));
    $copy = $source->copy();
    $copy->onGet('tenant.name', static fn(string $name): string => '[' . $name . ']');
    $copy->onSet('count', static fn(int $n): int => $n + 10);
    $copy['tenant.name'] = 'beta';
    $copy['count'] = 2;
    $source['count'] = 3;
    verify($copy['tenant.name'] === '[BETA]' && $source['tenant.name'] === 'ALPHA', 'Copied hook registry leaked to source.');
    verify($copy->all() === ['tenant' => ['name' => 'beta'], 'count' => 12], 'Copy stored wrong values.');
    verify($source->all() === ['tenant' => ['name' => 'alpha'], 'count' => 3], 'Copy mutated source data or hooks.');
    echo "Hook registry and nested data remain isolated after copy\n";
}

function arrayFacadeMutationsWorkload(): void
{
    $dot = ArrayKit::dot();
    $data = ['order' => ['amount' => 0, 'id' => 7], 'tenant' => 'alpha'];
    $dot->fill($data, 'order.amount', 99);
    $dot->fill($data, 'order.currency', 'USD');
    verify($dot->set($data, ['order.amount' => 10, 'order.paid' => false]), 'Facade set failed.');
    $dot->offsetSet($data, 'order.note', null);
    verify($dot->offsetExists($data, 'order.note') && $dot->offsetGet($data, 'order.note') === null, 'Facade null presence failed.');
    verify($dot->move($data, 'order.id', 'order.reference'), 'Facade move failed.');
    verify($dot->rename($data, 'order.currency', 'order.unit'), 'Facade rename failed.');
    $dot->offsetUnset($data, 'order.note');
    $dot->forget(target: $data, keys: 'order.paid');
    verify($data === ['order' => ['amount' => 10, 'reference' => 7, 'unit' => 'USD'], 'tenant' => 'alpha'], 'Facade failed to mutate caller-owned array.');
    echo "Facade reference-preserving mutators and native named target passed\n";
}

function arrayFacadeRejectionWorkload(): void
{
    $data = ['from' => 1, 'to' => 2];
    $dot = ArrayKit::dot();
    verify(!$dot->rename($data, 'from', 'to', false), 'Facade allowed rejected rename collision.');
    verify(!$dot->move($data, 'missing', 'to'), 'Facade accepted missing source.');
    expectFailure(static fn() => $dot->forget(keys: 'from'), ArgumentCountError::class, 'Missing target accepted.');
    expectFailure(static function () use (&$data): void {
        ArrayKit::single()->forget(target: $data, keys: 'from');
    }, InvalidArgumentException::class, 'Non-dot module accepted native target argument.');
    expectFailure(static fn() => $dot->unknownLabMethod(), BadMethodCallException::class, 'Unknown module method accepted.');
    verify($data === ['from' => 1, 'to' => 2], 'Rejected operations mutated caller data.');
    verify($dot->move($data, 'from', 'new') && $data === ['to' => 2, 'new' => 1], 'Facade did not recover after rejected operations.');
    echo "Facade rejected collisions, invalid named arguments and unknown methods without mutation\n";
}

function configTypedDefaultsWorkload(): void
{
    $config = ArrayKit::config(['service' => [
        'port' => '5432', 'amount' => 3, 'enabled' => 'false', 'label' => 123,
        'list' => ['named' => 1], 'null' => null, 'real' => 1.25, 'flag' => false,
    ]]);
    foreach ([false, true] as $cache) {
        $config->readCache($cache);
        verify($config->getInt('service.port', 42) === 42, 'Integer accessor coerced a string.');
        verify($config->getFloat('service.amount', 2.5) === 2.5, 'Float accessor coerced an integer.');
        verify($config->getBool('service.enabled', true) === true, 'Boolean accessor coerced a string.');
        verify($config->getString('service.label', 'fallback') === 'fallback', 'String accessor coerced an integer.');
        verify($config->getList('service.list', [9]) === [9] && $config->getArray('service.list') === ['named' => 1], 'List/map distinction lost.');
        verify($config->getFloat('service.real') === 1.25 && $config->getBool('service.flag', true) === false, 'Valid typed value changed.');
        verify($config->get('service.null', 'fallback') === null && $config->getInt('service.null', 8) === 8, 'Null and typed fallback diverged.');
    }
    echo "Strict typed config fallback and list/map distinction passed with cache on and off\n";
}

function configLazyDefaultsWorkload(): void
{
    $config = ArrayKit::config(['service' => ['nil' => null, 'zero' => 0]]);
    $config->readCache(true);
    $calls = 0;
    $fallback = static function () use (&$calls): int {
        return ++$calls;
    };
    verify($config->get('service.nil', $fallback) === null && $config->get('service.zero', $fallback) === 0 && $calls === 0, 'Present falsey values invoked a lazy default.');
    verify($config->get('service.missing', $fallback) === 1 && $config->get('service.missing', $fallback) === 2, 'Missing-key cache retained computed default.');
    verify($config->set('service.missing', 0) && $config->get('service.missing', $fallback) === 0 && $calls === 2, 'Mutation failed to invalidate cached miss.');
    verify($config->forget('service.missing') && $config->get('service.missing', $fallback) === 3, 'Deletion failed to invalidate cached hit.');
    expectFailure(static fn() => $config->get('service.failure', static fn() => throw new DomainException('Unavailable default')), DomainException::class, 'Default failure concealed.');
    verify($config->get('service.failure', $fallback) === 4 && !$config->has('service.failure'), 'Default failure or evaluation created stored state.');
    echo "Lazy defaults remain caller-owned across cached misses, mutation and exception recovery\n";
}
