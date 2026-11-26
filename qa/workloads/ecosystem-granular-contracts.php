<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Array\ArrayMulti;
use Infocyph\ArrayKit\Array\ArraySingle;
use Infocyph\ArrayKit\Array\DotNotation;
use Infocyph\ArrayKit\Config\Config;
use Infocyph\ReqShield\CompiledValidator;
use Infocyph\ReqShield\Rules\Contains;
use Infocyph\ReqShield\Rules\NotRegex;
use Infocyph\ReqShield\Rules\Regex;
use Infocyph\ReqShield\Validator;

function granularValidationWorkload(string $family): void
{
    $cases = match ($family) {
        'sizes' => [
            ['between:2,4', 2, 1], ['between:2,4', 4, 5],
            ['size:2', 'é文', 'é文a'], ['min:2', [1, 2], [1]],
            ['max:2', [1, 2], [1, 2, 3]], ['size:2', ['size' => 2048], ['size' => 2049]],
            ['multiple_of:0.25', 1.25, 1.1], ['multiple_of:0.25', -1.25, -1.1],
        ],
        'dates' => [
            ['date_format:Y-m-d', '2024-02-29', '2025-02-29'],
            ['date_format:Y-m-d', '2024-12-31', '2024-13-01'],
            ['date_format:Y-m-d', '2024-01-01', '2024-1-1'],
            ['date_format:Y-m-d H:i:s', '2024-01-01 23:59:59', '2024-01-01 24:00:00'],
            ['before_or_equal:2024-02-29', '2024-02-29', '2024-03-01'],
            ['after_or_equal:2024-02-29', '2024-02-29', '2024-02-28'],
        ],
        'collections' => [
            ['required_array_keys:a,b', ['a' => null, 'b' => false], ['a' => null]],
            ['is_list', [0 => 0, 1 => null], [0 => 0, 2 => null]],
            [[new Contains(0)], [0, false], ['0', false]],
            [[new Contains(false)], [false, null], [0, null]],
            ['contains:0', 'A0B', 'AB'],
            ['doesnt_contain:root,admin', 'member', 'member-admin'],
        ],
        'patterns' => [
            [[new Regex('/^(?:order|receipt)-[0-9]+$/D')], 'order-42', 'order-42-x'],
            [[new Regex('/^\p{L}+$/uD')], 'ব', 'বাংলা'],
            [[new Regex('/^(?:\p{L}\p{M}*)+$/uD')], 'বাংলা', 'বাংলা1'],
            [[new NotRegex('/(?:root|admin)/i')], 'member', 'ADMIN'],
            ['alpha', 'AbZ', 'é'],
            ['alpha_dash', 'order_42-A', 'order/42'],
            ['ascii', 'ABC', '文'],
        ],
        'network' => [
            ['ip:v4,public', '8.8.8.8', '127.0.0.1'],
            ['ip:v6,public', '2001:4860:4860::8844', '::1'],
            ['ip:v4,private', '192.168.1.1', '2001:4860:4860::8844'],
            ['ip:v6,private', 'fd00::1234', '8.8.8.8'],
            ['mac', '00:1A:2B:3C:4D:5E', '00:1A:2B:3C:4D:ZZ'],
            ['timezone', 'Asia/Dhaka', 'asia/dhaka'],
        ],
        default => throw new InvalidArgumentException('Unknown granular validation family.'),
    };
    foreach ($cases as $index => [$rule, $good, $bad]) {
        validationPair($family . ' case ' . $index, $rule, ['v' => $good], ['v' => $bad]);
    }
    if ($family === 'patterns') {
        foreach ([Regex::class, NotRegex::class] as $class) {
            expectFailure(static fn() => new $class('/[/'), InvalidArgumentException::class, 'Invalid regex configuration accepted.');
        }
    }
    echo $family . " granular direct/compiled validation passed\n";
}

function validationPresenceTruthTableWorkload(): void
{
    $cases = [
        ['required_with:a,b', ['a' => false], [], ['a' => false, 'v' => 0]],
        ['required_with_all:a,b', ['a' => false, 'b' => 0], ['a' => false], ['a' => false, 'b' => 0, 'v' => false]],
        ['required_without:a,b', ['a' => 0], ['a' => 0, 'b' => false], ['a' => 0, 'v' => false]],
        ['required_without_all:a,b', [], ['a' => false], ['v' => 0]],
        ['present_with:a,b', ['a' => null], [], ['a' => null, 'v' => null]],
        ['present_with_all:a,b', ['a' => null, 'b' => false], ['a' => null], ['a' => null, 'b' => false, 'v' => null]],
    ];
    foreach ($cases as [$rule, $missing, $inactive, $valid]) {
        $builder = Validator::make(['v' => $rule])->setFailFast(false);
        foreach ([$builder, new CompiledValidator($builder)] as $validator) {
            verify($validator->validate($missing)->hasError('v'), 'Triggered presence rule missed absent field: ' . $rule);
            verify($validator->validate($inactive)->passes(), 'Inactive presence rule rejected input: ' . $rule);
            verify($validator->validate($valid)->passes(), 'Present falsey value rejected: ' . $rule);
        }
    }
    echo "Conditional presence and nonempty-field truth tables passed\n";
}

function validationDistinctGroupsWorkload(): void
{
    $builder = Validator::make(['batches.*.items.*.sku' => 'required|string|distinct'])->setFailFast(false);
    $good = ['batches' => [
        ['items' => [['sku' => 'A'], ['sku' => 'B']]],
        ['items' => [['sku' => 'A'], ['sku' => 'C']]],
    ]];
    $bad = $good;
    $bad['batches'][1]['items'][1]['sku'] = 'A';
    foreach ([$builder, new CompiledValidator($builder)] as $validator) {
        verify($validator->validate($good)->passes(), 'Distinct group crossed batch boundary.');
        $result = $validator->validate($bad);
        verify($result->hasError('batches.1.items.0.sku') && $result->hasError('batches.1.items.1.sku'), 'Distinct duplicates not independently identified.');
        verify(!$result->hasError('batches.0.items.0.sku') && !$result->hasError('batches.0.items.1.sku'), 'Duplicate error leaked to independent batch.');
        verify($validator->validate($good)->passes(), 'Duplicate state survived validator reuse.');
    }
    echo "Multi-level wildcard distinct groups and reuse passed\n";
}

function arrayNumericDomainsWorkload(): void
{
    $values = ['neg' => -2, 'zero' => 0, 'pos' => 3, 'string' => '4', 'other' => 'text'];
    verify(ArraySingle::positive($values) === array_filter($values, static fn($v): bool => is_numeric($v) && $v > 0), 'Positive selection changed keys or values.');
    verify(ArraySingle::negative($values) === array_filter($values, static fn($v): bool => is_numeric($v) && $v < 0), 'Negative selection changed keys or values.');
    verify(ArraySingle::isInt([0, -2, 3]) && !ArraySingle::isInt(['3']) && !ArraySingle::isInt([3.0]), 'Integer classification coerced type.');
    verify(ArraySingle::isPositive([1, '2', 'ignored']) && !ArraySingle::isPositive([1, 0]) && !ArraySingle::isPositive(['ignored']), 'Positive domain boundary changed.');
    verify(ArraySingle::isNegative([-1, '-2', 'ignored']) && !ArraySingle::isNegative([-1, 0]) && !ArraySingle::isNegative(['ignored']), 'Negative domain boundary changed.');
    echo "Numeric classification and keyed filtering passed\n";
}

function arrayBucketProjectionWorkload(): void
{
    $rows = [
        'a' => ['tenant' => 'north', 'amount' => 3],
        'b' => ['tenant' => 'south', 'amount' => 7],
        'c' => ['tenant' => 'north', 'amount' => -2],
        'd' => ['tenant' => 'south', 'amount' => 5],
    ];
    $expected = [];
    foreach ($rows as $row) {
        $expected[$row['tenant']] = ($expected[$row['tenant']] ?? 0) + 1;
    }
    verify(ArraySingle::countBy($rows, static fn(array $row): string => $row['tenant']) === $expected, 'Bucket projection differs from independent count.');
    verify(ArraySingle::maxBy($rows, static fn(array $row): int => $row['amount']) === $rows['b'], 'Max projection wrong.');
    verify(ArraySingle::minBy($rows, static fn(array $row): int => $row['amount']) === $rows['c'], 'Min projection wrong.');
    verify(ArraySingle::countBy([]) === [] && ArraySingle::maxBy([], static fn($v) => $v) === null && ArraySingle::minBy([], static fn($v) => $v) === null, 'Empty aggregation changed.');
    echo "Bucket and extremum projection passed\n";
}

function arrayMatrixShapeWorkload(): void
{
    $rows = [['a' => 1, 'b' => null], ['a' => false, 'b' => 2], ['a' => 0, 'b' => 3]];
    verify(ArrayMulti::transpose($rows) === ['a' => [1, false, 0], 'b' => [null, 2, 3]], 'Transpose coerced falsey values.');
    verify(ArrayMulti::transpose([]) === [], 'Empty transpose changed.');
    foreach ([[['a' => 1], ['b' => 2]], [['a' => 1], ['a' => 2, 'b' => 3]], [1, 2]] as $bad) {
        expectFailure(static fn() => ArrayMulti::transpose($bad), InvalidArgumentException::class, 'Ragged matrix accepted.');
    }
    verify(ArrayMulti::collapse([[1, 2], 'ignored', [], [3, [4]]]) === [1, 2, 3, [4]], 'One-level collapse changed nesting.');
    verify(ArrayMulti::flattenByKey(['a' => [1, ['b' => null]], 'c' => false]) === [1, null, false], 'Full traversal changed leaf values.');
    echo "Matrix shape rejection and nested leaf projections passed\n";
}

function arrayPathMutationWorkload(): void
{
    $data = ['source' => ['nil' => null, 'zero' => 0], 'target' => ['existing' => false]];
    $before = $data;
    verify(!DotNotation::move($data, 'source.zero', 'target.existing', overwrite: false) && $data === $before, 'Rejected move mutated data.');
    verify(DotNotation::move($data, 'source.zero', 'target.zero') && !DotNotation::has($data, 'source.zero') && DotNotation::get($data, 'target.zero') === 0, 'Move lost zero or source ownership.');
    verify(DotNotation::rename($data, 'source.nil', 'target.nil') && DotNotation::has($data, 'target.nil') && DotNotation::get($data, 'target.nil', 'missing') === null, 'Rename lost null presence.');
    DotNotation::fill($data, 'target.existing', true);
    verify(DotNotation::get($data, 'target.existing') === false, 'Fill replaced false.');
    $before = $data;
    verify(!DotNotation::move($data, 'missing.path', 'target.new') && $data === $before, 'Missing move modified data.');
    echo "Path move, rename, collision and null/false ownership passed\n";
}

function configReadCacheToggleWorkload(): void
{
    $config = new Config();
    verify($config->loadArray(['service' => ['port' => 5432, 'enabled' => false, 'nullable' => null]]), 'Config fixture unavailable.');
    foreach ([true, false, true] as $enabled) {
        $config->readCache($enabled);
        verify($config->readCacheEnabled() === $enabled, 'Read cache toggle ignored.');
        verify($config->getInt('service.port') === 5432 && $config->getBool('service.enabled') === false, 'Toggle changed typed reads.');
        verify($config->has('service.nullable') && $config->get('service.nullable', 'missing') === null, 'Toggle lost null presence.');
        verify($config->set('service.port', 6432) && $config->getInt('service.port') === 6432, 'Mutation left stale cached read.');
        verify($config->forget('service.port') && $config->get('service.port', 'missing') === 'missing', 'Forget resurrected cached value.');
        $config->flushReadCache();
        verify($config->set('service.port', 5432) && $config->getInt('service.port') === 5432, 'Cache flush changed restored read.');
    }
    echo "Read cache toggle, mutation invalidation and null presence passed\n";
}
