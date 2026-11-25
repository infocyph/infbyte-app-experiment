<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Array\ArrayMulti;
use Infocyph\ArrayKit\Array\ArrayShape;
use Infocyph\ArrayKit\Array\ArraySingle;
use Infocyph\ArrayKit\Array\BaseArrayHelper;
use Infocyph\ArrayKit\Array\DotNotation;
use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\ArrayKit\DTO\Concerns\DTOTrait;

function arraySetContractsWorkload(): void
{
    $rows = [];
    for ($i = 0; $i < 1500; ++$i) {
        $rows['order-' . $i] = ['id' => $i, 'tenant' => $i % 5, 'amount' => $i % 17];
    }
    // Use native PHP membership as an independent reference across both small and large input paths.
    foreach ([1, 15, 255, 256, 512, 1501] as $size) {
        $needles = range(0, $size - 1);
        foreach ([false, true] as $strict) {
            $expected = array_filter($rows, static fn(array $row): bool => in_array($row['id'], $needles, $strict));
            $remainder = array_filter($rows, static fn(array $row): bool => !in_array($row['id'], $needles, $strict));
            verify(ArrayMulti::whereIn($rows, 'id', $needles, $strict) === $expected, 'Row membership threshold diverged.');
            verify(ArrayMulti::whereNotIn($rows, 'id', $needles, $strict) === $remainder, 'Row exclusion threshold diverged.');
            verify(ArraySingle::intersect(range(0, 1499), $needles, $strict) === array_column($expected, 'id'), 'Scalar intersection mismatch.');
        }
    }
    $sorted = ArrayMulti::sortByMany($rows, [['tenant', 'asc'], ['amount', 'desc'], ['id', 'asc']]);
    $reference = $rows;
    uasort($reference, static fn(array $a, array $b): int => ($a['tenant'] <=> $b['tenant']) ?: ($b['amount'] <=> $a['amount']) ?: ($a['id'] <=> $b['id']));
    verify($sorted === $reference, 'Multi-key sort diverged from independent reference.');
    verify(ArrayMulti::countBy($rows, 'tenant') === array_fill(0, 5, 300), 'Group counts diverged.');
    verify(ArrayMulti::sum($rows, 'amount') === array_sum(array_column($rows, 'amount')), 'Row sum diverged.');
    $large = 9_007_199_254_740_993;
    verify(ArraySingle::sum([$large, -($large - 1)]) === 1 && ArraySingle::max([$large - 1, $large]) === $large, 'Integer precision lost.');
    verify(ArraySingle::unique([1, '1', true, [1], ['1']], true) === [1, '1', true, [1], ['1']], 'Strict set collapsed unlike types.');
    verify(count(ArraySingle::unique([INF, -INF, NAN, NAN], true)) === 4, 'Nonfinite equality changed.');
    verify(ArraySingle::same([1, 2, 2], [2, 1, 2]) && !ArraySingle::same([1, 2], ['1', 2], true), 'Multiset equality mismatch.');
    foreach ([null, false, 1.5, [], new stdClass()] as $invalid) {
        $fixture = [['group' => $invalid]];
        foreach (['groupBy', 'keyBy', 'countBy'] as $operation) {
            expectFailure(static fn() => ArrayMulti::$operation($fixture, 'group'), InvalidArgumentException::class, 'Invalid group key accepted.');
        }
    }
    foreach ([false, 0, 0.0, '0', ''] as $value) {
        verify(BaseArrayHelper::wrap($value) === [$value], 'Falsey data was discarded.');
    }
    verify(BaseArrayHelper::wrap(null) === [], 'Null wrapping mismatch.');
    expectFailure(static fn() => ArrayMulti::transpose([[1, 2], [3]]), InvalidArgumentException::class, 'Ragged transpose accepted.');
    expectFailure(static fn() => ArraySingle::nth([1, 2], 0), InvalidArgumentException::class, 'Invalid step accepted.');
    $collection = Collection::make($rows);
    $derived = $collection->copy()->where('tenant', 2)->sortByMany([['amount', 'desc'], ['id', 'asc']])->all();
    verify($derived === array_filter($reference, static fn(array $row): bool => $row['tenant'] === 2) && $collection->all() === $rows, 'Collection projection or snapshot diverged.');
    echo "Adaptive membership, stable ordering, strict sets, numeric precision, grouping, shape and collection isolation passed\n";
}

final class LabHydratedAddress
{
    use DTOTrait;
    public string $city = '';
    public int $postal = 0;
}

final class LabHydratedOrder
{
    use DTOTrait;
    public int $quantity = 0;
    public bool $paid = false;
    public float $amount = 0.0;
    public ?string $note = null;
    public ?LabHydratedAddress $address = null;
    public array $tags = [];
    public ?self $related = null;
    private string $internal = 'private';
}

function arrayDtoGuardWorkload(): void
{
    $dto = new LabHydratedOrder();
    $dto->hydrateNestedGuarded([
        'units' => '2', 'paid' => 'yes', 'amount' => '12.5', 'note' => null,
        'address' => ['city' => 'Dhaka', 'postal' => 1200],
        'tags' => 'receipt', 'internal' => 'overwrite', 'unknown' => 1,
    ], ['units' => 'quantity'], coerce: true, maxDepth: 8, maxNodes: 100);
    $expected = [
        'quantity' => 2, 'paid' => true, 'amount' => 12.5, 'note' => null,
        'address' => ['city' => 'Dhaka', 'postal' => 1200], 'tags' => ['receipt'], 'related' => null,
    ];
    verify($dto->address instanceof LabHydratedAddress && $dto->toArrayDeepGuarded() === $expected && $dto->toArrayDeep() === $expected, 'Nested typed DTO projection mismatch.');
    $shape = ['quantity' => 'int', 'paid' => 'bool', 'amount' => 'float', 'note' => 'null', 'tags' => 'list<string>', 'optional?' => 'string'];
    verify(ArrayShape::require($expected, $shape) === $expected, 'Valid shape changed.');
    foreach ([['quantity' => '2'], ['tags' => [3]], ['tags' => ['key' => 'receipt']], ['note' => false]] as $fault) {
        expectFailure(static fn() => ArrayShape::require(array_replace($expected, $fault), $shape), InvalidArgumentException::class, 'Invalid shape accepted.');
    }
    $cycle = [];
    $cycle['self'] = &$cycle;
    expectFailure(static fn() => $dto->hydrateNestedGuarded(['quantity' => 99, 'tags' => $cycle]), RuntimeException::class, 'Cyclic input accepted.');
    verify($dto->toArrayDeepGuarded() === $expected, 'Guarded failure partially mutated DTO.');
    expectFailure(static fn() => $dto->hydrateNestedGuarded(['quantity' => 99, 'tags' => [[[[1]]]]], maxDepth: 2), RuntimeException::class, 'Depth overflow accepted.');
    expectFailure(static fn() => $dto->hydrateNestedGuarded(['quantity' => 99, 'tags' => range(1, 200)], maxNodes: 10), RuntimeException::class, 'Node overflow accepted.');
    verify($dto->toArrayDeepGuarded() === $expected, 'Budget failure partially mutated DTO.');
    $dto->related = $dto;
    expectFailure(static fn() => $dto->toArrayDeepGuarded(), RuntimeException::class, 'Object cycle exported.');
    $dto->related = null;
    verify($dto->toArrayDeepGuarded() === $expected, 'Guarded export retained cycle state.');
    $dto->replaceFromArray(['quantity' => '3'], coerce: true);
    verify($dto->quantity === 3 && $dto->address?->city === 'Dhaka', 'Incremental hydration lost nested state.');

    $data = ['service.name' => ['path\\part' => ['*' => ['{first}' => ['{last}' => 'value']]]]];
    $flat = DotNotation::flatten($data);
    verify(DotNotation::expand($flat) === $data && DotNotation::paths($data) === array_keys($flat), 'Literal path controls did not roundtrip.');
    $data = ['users' => [['name' => 'A'], ['name' => 'B']]];
    verify(DotNotation::getSafe($data, 'users.*.name', 'missing', maxDepth: 1) === ['missing', 'missing'], 'Bounded fallback changed.');
    expectFailure(static fn() => DotNotation::getSafe($data, 'users.*.name', 'missing', maxDepth: 1, throwOnTooDeep: true), RuntimeException::class, 'Traversal budget ignored.');
    DotNotation::set($data, 'users.*.active', false);
    verify(DotNotation::get($data, 'users.*.active') === [false, false], 'Wildcard write failed.');
    DotNotation::forget($data, 'users.*');
    verify($data['users'] === [], 'Terminal wildcard removal failed.');
    $parent = ['a' => ['b' => 1, 'c' => 2]];
    verify(DotNotation::rename($parent, 'a', 'a.moved') && $parent === ['a' => ['moved' => ['b' => 1, 'c' => 2]]], 'Overlapping path move lost source.');
    echo "Typed DTO hydration, shape contracts, guarded cycle/depth/node rejection and bounded path mutation passed\n";
}
