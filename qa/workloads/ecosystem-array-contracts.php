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

function arrayHelperBoundariesWorkload(): void
{
    $rows = ['a' => 0, 7 => 2, 'b' => 4, 10 => 6, 'c' => 8];
    $even = static fn(int $value): bool => $value % 4 === 0;
    foreach ([ArraySingle::class, ArrayMulti::class] as $type) {
        verify($type::chunk($rows, 2, true) === array_chunk($rows, 2, true), 'Chunk lost source keys.');
        verify($type::partition($rows, $even) === [array_filter($rows, $even), array_filter($rows, static fn(int $value): bool => !$even($value))], 'Partition changed key ownership.');
        verify($type::skip($rows, 2) === array_slice($rows, 2, null, true), 'Skip key semantics changed.');
        verify($type::skipWhile($rows, static fn(int $value): bool => $value < 4) === array_slice($rows, 2, null, true), 'Skip-while boundary failed.');
        verify($type::skipUntil($rows, static fn(int $value): bool => $value >= 4) === array_slice($rows, 2, null, true), 'Skip-until boundary failed.');
        $visited = [];
        verify($type::each($rows, static function (int $value, int|string $key) use (&$visited): bool {
            $visited[] = $key;
            return $value < 4;
        }) === $rows && $visited === ['a', 7, 'b'], 'Early iteration exit or ownership failed.');
        verify($type::rekey($rows, ['a' => 'zero', 7 => 'two']) === ['zero' => 0, 'two' => 2, 'b' => 4, 10 => 6, 'c' => 8], 'Mapped key replacement failed.');
        expectFailure(static fn() => $type::rekey($rows, static fn(): array => []), InvalidArgumentException::class, 'Invalid mapped key accepted.');
    }
    verify(ArraySingle::paginate($rows, 2, 2) === ['b' => 4, 10 => 6] && ArraySingle::paginate($rows, PHP_INT_MAX, 2) === [], 'Pagination boundary/overflow failed.');
    expectFailure(static fn() => ArraySingle::paginate($rows, 0, 1), InvalidArgumentException::class, 'Invalid page accepted.');
    expectFailure(static fn() => ArraySingle::paginate($rows, 1, 0), InvalidArgumentException::class, 'Invalid page size accepted.');
    verify(ArraySingle::nth($rows, 2, 1) === [2, 6], 'Nth offset changed.');
    verify(ArraySingle::search([0 => false, 'one' => '1'], false) === 0 && ArraySingle::search(['one' => '1'], 1) === null, 'Search confused missing and falsey keys.');
    verify(ArraySingle::separate($rows) === ['keys' => array_keys($rows), 'values' => array_values($rows)], 'Separate changed ownership.');
    verify(ArraySingle::mapWithKeys($rows, static fn(int $value): array => ['v' . $value => $value * 3]) === ['v0' => 0, 'v2' => 6, 'v4' => 12, 'v6' => 18, 'v8' => 24], 'Map-with-keys failed.');
    verify(ArraySingle::nonEmpty(['empty' => '', 'null' => null, 'false' => false, 'zero' => 0], true) === ['null' => null, 'false' => false, 'zero' => 0], 'Non-empty removed non-string falsey values.');
    verify(ArraySingle::avg([1, 2, 3, 'invalid']) === 2 && ArraySingle::median([9, 1, 5, 3]) === 4 && ArraySingle::mode(['a', 'b', 'a', 'b', 'c']) === ['a', 'b'], 'Numeric/statistical contract failed.');
    verify(is_finite(ArraySingle::avg([PHP_FLOAT_MAX, PHP_FLOAT_MAX])) && ArraySingle::median([PHP_FLOAT_MAX, PHP_FLOAT_MAX]) === PHP_FLOAT_MAX, 'Finite average/median overflowed.');
    $shuffled = ArraySingle::shuffle($rows, 42);
    verify($shuffled === ArraySingle::shuffle($rows, 42) && ArraySingle::same($shuffled, $rows, true), 'Seeded shuffle changed membership or repeatability.');
    verify(BaseArrayHelper::random([], null) === null && BaseArrayHelper::random([], 0) === [] && BaseArrayHelper::random($rows, count($rows), true) === $rows, 'Random selection ownership failed.');
    expectFailure(static fn() => BaseArrayHelper::random($rows, 6), InvalidArgumentException::class, 'Oversized random selection accepted.');
    verify(BaseArrayHelper::times(4, static fn(int $i): int => $i * 2) === [2, 4, 6, 8] && BaseArrayHelper::range(5, 1, 2) === [5, 3, 1], 'Range/times boundary failed.');
    expectFailure(static fn() => BaseArrayHelper::range(1, 5, 0), InvalidArgumentException::class, 'Zero step accepted.');

    $nested = ['first' => [1, [2, 3]], 'second' => [4], 'last' => 5];
    verify(ArrayMulti::flatten($nested, 0) === array_values($nested) && ArrayMulti::flatten($nested, 1) === [1, [2, 3], 4, 5], 'Flatten depth boundary failed.');
    verify(ArrayMulti::flattenGuarded($nested) === [1, 2, 3, 4, 5] && ArrayMulti::depthGuarded($nested) === 3, 'Guarded traversal changed valid nested data.');
    verify(ArrayMulti::flattenGuarded($nested, maxNodes: 2) === [1], 'Non-throwing traversal budget failed.');
    expectFailure(static fn() => ArrayMulti::flattenGuarded($nested, maxNodes: 2, throwOnTooDeep: true), RuntimeException::class, 'Traversal node budget ignored.');
    expectFailure(static fn() => ArrayMulti::depthGuarded($nested, maxDepth: 2, throwOnTooDeep: true), RuntimeException::class, 'Traversal depth budget ignored.');
    $cycle = [];
    $cycle['loop'] = &$cycle;
    expectFailure(static fn() => ArrayMulti::flattenGuarded($cycle, maxDepth: 8, throwOnTooDeep: true), RuntimeException::class, 'Cyclic traversal escaped bounds.');
    unset($cycle['loop']);
    $base = ['map' => ['a' => 1, 'b' => 2], 'list' => [1, 2], 'flag' => true];
    $overlay = ['map' => ['a' => null], 'list' => [9], 'flag' => false];
    verify(ArrayMulti::mergeRecursiveDistinct($base, $overlay) === array_replace_recursive($base, $overlay) && ArrayMulti::replaceRecursive($base, $overlay) === array_replace_recursive($base, $overlay), 'Array recursive replacement changed.');
    verify(\Infocyph\ArrayKit\Config\ConfigMerge::merge($base, $overlay) === ['map' => ['a' => null, 'b' => 2], 'list' => [9], 'flag' => false], 'Config list replacement confused array merge semantics.');
    verify(ArrayMulti::transpose([['a' => 1, 'b' => 2], ['a' => 3, 'b' => 4]]) === ['a' => [1, 3], 'b' => [2, 4]], 'Associative matrix transpose failed.');
    echo "Array helper key ownership, callback boundaries, statistics, budgets and recursive merge semantics passed\n";
}

enum LabConfigMode: string
{
    case Live = 'live';
    case Test = 'test';
}

function configMutationLifecycleWorkload(): void
{
    $root = evidenceDirectory() . '/config-lifecycle';
    verify(mkdir($root, 0700), 'Config fixture directory unavailable.');
    verify(file_put_contents($root . '/service.php', "<?php return ['port'=>5432,'enabled'=>false,'mode'=>'live','list'=>[1,2],'map'=>['a'=>1],'nil'=>null];", LOCK_EX) !== false, 'Config source write failed.');
    $config = new \Infocyph\ArrayKit\Config\LayeredLazyFileConfig($root, namespaces: ['service']);
    $config->readCache(true);
    verify($config->readCacheEnabled() && $config->getInt('service.port') === 5432 && $config->getBool('service.enabled') === false, 'Typed cached reads failed.');
    verify($config->getEnum('service.mode', LabConfigMode::class) === LabConfigMode::Live && $config->getList('service.list') === [1, 2] && $config->getArray('service.map') === ['a' => 1], 'Enum/list/map read failed.');
    verify($config->get('service.nil', 'fallback') === null && $config->get('service.missing', 'fallback') === 'fallback', 'Missing/null distinction lost.');
    expectFailure(static fn() => $config->getOrFail('service.missing'), OutOfBoundsException::class, 'Required missing key accepted.');
    $initial = $config->all();
    verify($config->snapshot('release'), 'Release snapshot failed.');
    $config->fill('service.enabled', true);
    verify($config->getBool('service.enabled') === false, 'Fill overwrote false.');
    $config->set('service.port', 6432);
    $config->append('service.list', 3);
    $config->prepend('service.list', 0);
    $config->forget('service.map.a');
    verify($config->getInt('service.port') === 6432 && $config->getList('service.list') === [0, 1, 2, 3] && !$config->has('service.map.a'), 'Mutation left stale cached reads.');
    verify($config->changed('release') && $config->restore('release') && $config->all() === $initial, 'Snapshot did not restore full config.');
    $config->readonly();
    verify($config->isReadonly(), 'Read-only mode not enabled.');
    foreach ([
        static fn() => $config->set('service.port', 1),
        static fn() => $config->fill('service.new', true),
        static fn() => $config->forget('service.port'),
        static fn() => $config->merge(['service' => ['port' => 1]]),
        static fn() => $config->replace([]),
        static fn() => $config->restore('release'),
    ] as $mutation) {
        expectFailure($mutation, RuntimeException::class, 'Read-only configuration mutated.');
    }
    verify($config->all() === $initial, 'Rejected read-only mutation changed data.');
    $config->readonly(false);
    $artifact = $root . '/compiled.php';
    verify($config->exportCache($artifact), 'Config export failed.');
    $replacement = new \Infocyph\ArrayKit\Config\Config();
    verify($replacement->loadCache($artifact) && $replacement->all() === $initial, 'Generated config did not load into replacement.');
    verify(!$config->reload($root . '/missing.php') && $config->all() === $initial, 'Missing reload changed configuration.');
    verify(file_put_contents($root . '/invalid.php', '<?php return false;', LOCK_EX) !== false, 'Invalid source fixture failed.');
    expectFailure(static fn() => $config->reload($root . '/invalid.php'), UnexpectedValueException::class, 'Invalid source reload accepted.');
    verify($config->all() === $initial, 'Invalid reload mutated active generation.');
    verify($config->reload(['replacement' => ['active' => true]]) && $config->all() === ['replacement' => ['active' => true]] && !$config->has('service.port'), 'Full replacement resurrected lazy namespaces.');
    verify($config->restore('release') && $config->all() === $initial, 'Old release restoration failed.');
    echo "Typed configuration reads, cached mutation, read-only protection, export, reload rejection and restoration passed\n";
}


function collectionOwnershipWorkload(): void
{
    $input = ['tenant' => ['name' => 'alpha', 'nil' => null, 'flag' => false], 'orders' => [1, 2]];
    $collection = \Infocyph\ArrayKit\Collection\Collection::from($input);
    verify(isset($collection['tenant.nil']) && $collection['tenant.nil'] === null, 'Null key presence lost.');
    verify($collection->has(['tenant.nil', 'tenant.flag']) && $collection->hasAny(['missing', 'tenant.flag']) && !$collection->hasAny(['missing']), 'Collection presence mismatch.');
    verify(iterator_to_array($collection) === $input && $collection() === $input && $collection->items() === $input, 'Collection projections diverged.');
    $copy = $collection->immutable();
    $copy['tenant.name'] = 'beta';
    unset($copy['tenant.nil']);
    $copy->status = 'ready';
    $copy[] = 'tail';
    verify($copy->get('tenant.name') === 'beta' && !$copy->has('tenant.nil') && $copy->status === 'ready' && $copy[0] === 'tail', 'Copy mutation mismatch.');
    verify($collection->all() === $input, 'Copy mutated original array.');
    $copy->clear();
    verify($copy->isEmpty() && count($collection) === 2, 'Clear crossed ownership.');
    $source = static function (): Generator { yield 'a' => 1; yield 'b' => 2; };
    $from = \Infocyph\ArrayKit\Collection\Collection::make($source());
    verify($from->all() === ['a' => 1, 'b' => 2], 'Traversable import lost keys.');
    $from->merge(new ArrayIterator(['a' => 3, 'c' => 4]));
    verify($from->all() === ['a' => 3, 'b' => 2, 'c' => 4], 'Merge precedence mismatch.');
    $json = new class implements JsonSerializable {
        public function jsonSerialize(): array { return ['receipt' => 42]; }
    };
    verify(\Infocyph\ArrayKit\Collection\Collection::make($json)->all() === ['receipt' => 42], 'JSON serializable import failed.');
    $nested = \Infocyph\ArrayKit\Collection\Collection::make(['record' => $json]);
    verify($nested->jsonSerialize() === ['record' => ['receipt' => 42]], 'JSON projection failed.');
    $numbers = \Infocyph\ArrayKit\Collection\Collection::make([1, 2, 3, 4]);
    verify($numbers->immutableProcess()->filter(static fn(int $n): bool => $n > 2)->map(static fn(int $n): int => $n * 2)->all() === [2 => 6, 3 => 8], 'Immutable pipeline changed keys or values.');
    verify($numbers->all() === [1, 2, 3, 4], 'Immutable pipeline mutated source.');
    expectFailure(static fn() => $numbers->labUnknownMethod(), BadMethodCallException::class, 'Unknown collection method accepted.');
    echo "Collection array/property access, presence, projections, copy, iterable import, merge and immutable pipeline passed\n";
}
