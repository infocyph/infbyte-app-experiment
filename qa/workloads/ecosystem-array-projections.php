<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Array\ArrayMulti;
use Infocyph\ArrayKit\Array\ArraySingle;
use Infocyph\ArrayKit\ArrayKit;

enum LabArrayStringState: string { case Active = 'active'; }
enum LabArrayIntState: int { case One = 1; }
enum LabArrayUnitState { case Ready; }

function arrayProjectionGroupKeys(): void
{
    $rows = ['a' => ['tenant' => 'north'], 8 => ['tenant' => 'south'], 'b' => ['tenant' => 'north'], 'missing' => [], 'scalar' => false];
    verify(ArrayMulti::groupBy($rows, 'tenant', true) === ['north' => ['a' => $rows['a'], 'b' => $rows['b']], 'south' => [8 => $rows[8]]], 'Grouping lost original keys or included missing rows.');
    verify(ArrayMulti::groupBy($rows, 'tenant') === ['north' => [$rows['a'], $rows['b']], 'south' => [$rows[8]]], 'Group list projection differs.');
    expectFailure(static fn() => ArrayMulti::groupBy([['tenant' => null]], 'tenant'), InvalidArgumentException::class, 'Null group key accepted.');
    echo "ArrayKit group-keys boundary passed\n";
}

function arrayProjectionGroupCallback(): void
{
    $rows = ['a' => ['amount' => 0], 'b' => ['amount' => -1], 'c' => ['amount' => 2]];
    $seen = [];
    $grouped = ArrayMulti::groupBy($rows, static function (array $row, string $key) use (&$seen): string {
        $seen[] = $key;
        return $row['amount'] >= 0 ? 'credit' : 'debit';
    }, true);
    verify($seen === ['a', 'b', 'c'] && $grouped === ['credit' => ['a' => $rows['a'], 'c' => $rows['c']], 'debit' => ['b' => $rows['b']]], 'Callback group provenance differs.');
    echo "ArrayKit group-callback boundary passed\n";
}

function arrayProjectionKeyCollision(): void
{
    $rows = [['id' => 'r1', 'value' => 0], ['id' => 'r2', 'value' => null], ['id' => 'r1', 'value' => false], ['value' => 99]];
    $expected = ['r1' => $rows[2], 'r2' => $rows[1]];
    verify(ArrayMulti::keyBy($rows, 'id') === $expected && ArrayMulti::indexBy($rows, 'id') === $expected, 'Duplicate key ownership differs.');
    expectFailure(static fn() => ArrayMulti::keyBy([['id' => []]], 'id'), InvalidArgumentException::class, 'Array index accepted.');
    echo "ArrayKit key-collision boundary passed\n";
}

function arrayProjectionPluckNull(): void
{
    $rows = [['id' => 'a', 'value' => null], ['id' => 'b', 'value' => false], ['id' => 'c'], ['value' => 0], ['id' => 'a', 'value' => 'last']];
    verify(ArrayMulti::pluck($rows, 'value') === [null, false, 0, 'last'], 'Pluck lost present null or falsey values.');
    verify(ArrayMulti::pluck($rows, 'value', 'id') === ['a' => 'last', 'b' => false], 'Indexed pluck lost ownership or accepted missing index.');
    expectFailure(static fn() => ArrayMulti::pluck([['id' => false, 'value' => 1]], 'value', 'id'), InvalidArgumentException::class, 'Invalid pluck index accepted.');
    echo "ArrayKit pluck-null boundary passed\n";
}

function arrayProjectionDerivedStrict(): void
{
    $rows = ['int' => ['id' => 1], 'string' => ['id' => '1'], 'repeat' => ['id' => 1], 'null' => ['id' => null], 'null-repeat' => ['id' => null], 'missing' => []];
    verify(array_keys(ArrayMulti::uniqueBy($rows, 'id', true)) === ['int', 'string', 'null'], 'Strict derived uniqueness conflated types.');
    verify(array_keys(ArrayMulti::duplicatesBy($rows, 'id', true)) === ['repeat', 'null-repeat'], 'Strict duplicates lost original occurrence keys.');
    echo "ArrayKit derived-strict boundary passed\n";
}

function arrayProjectionDerivedStructural(): void
{
    $rows = ['a' => ['id' => ['sku' => 1, 'tenant' => 'a']], 'b' => ['id' => ['tenant' => 'a', 'sku' => 1]], 'c' => ['id' => ['sku' => 1, 'tenant' => 'a']]];
    verify(array_keys(ArrayMulti::uniqueBy($rows, 'id', true)) === ['a', 'b'], 'Strict structural equality lost order semantics.');
    verify(array_keys(ArrayMulti::duplicatesBy($rows, 'id', false)) === ['b', 'c'], 'Loose structural equality diverged.');
    echo "ArrayKit derived-structural boundary passed\n";
}

function arrayProjectionQueryNull(): void
{
    $rows = ['null' => ['v' => null], 'zero' => ['v' => 0], 'false' => ['v' => false], 'empty' => ['v' => ''], 'missing' => [], 'scalar' => null];
    verify(array_keys(ArrayMulti::whereNull($rows, 'v')) === ['null'], 'Explicit null conflated with missing or falsey.');
    verify(array_keys(ArrayMulti::whereNotNull($rows, 'v')) === ['zero', 'false', 'empty'], 'Non-null filter lost falsey values.');
    echo "ArrayKit query-null boundary passed\n";
}

function arrayProjectionQueryLike(): void
{
    $rows = ['literal' => ['name' => 'order.a'], 'wild' => ['name' => 'order-b'], 'upper' => ['name' => 'ORDER.C'], 'extra' => ['name' => 'order.abc'], 'missing' => []];
    verify(array_keys(ArrayMulti::whereLike($rows, 'name', 'order._', true)) === ['literal'], 'SQL LIKE did not escape regex metacharacters or anchor pattern.');
    verify(array_keys(ArrayMulti::whereLike($rows, 'name', 'order.%')) === ['literal', 'upper', 'extra'], 'SQL LIKE wildcard or case policy differs.');
    echo "ArrayKit query-like boundary passed\n";
}

function arrayProjectionQueryText(): void
{
    $rows = ['upper' => ['name' => 'Order-ABC.PDF'], 'lower' => ['name' => 'order-abc.pdf'], 'other' => ['name' => 'other-abc.txt'], 'missing' => [], 'array' => ['name' => ['abc']]];
    verify(array_keys(ArrayMulti::whereStartsWith($rows, 'name', 'order-', false)) === ['upper', 'lower'], 'Case-insensitive prefix differs.');
    verify(array_keys(ArrayMulti::whereEndsWith($rows, 'name', '.PDF')) === ['upper'], 'Case-sensitive suffix differs.');
    verify(array_keys(ArrayMulti::whereContains($rows, 'name', 'ABC', false)) === ['upper', 'lower', 'other'], 'Substring boundary or malformed row differs.');
    echo "ArrayKit query-text boundary passed\n";
}

function arrayProjectionQueryFirst(): void
{
    $rows = ['missing' => [], 'string' => ['v' => '0'], 'zero' => ['v' => 0], 'null' => ['v' => null]];
    verify(ArrayMulti::firstWhereIn($rows, 'v', [0], true, 'fallback') === $rows['zero'], 'Strict first match chose coerced value.');
    verify(ArrayMulti::firstWhereIn($rows, 'v', [null], true, 'fallback') === $rows['null'], 'Null match chose missing row.');
    verify(ArrayMulti::firstWhereIn($rows, 'v', [], true, 'fallback') === 'fallback', 'No match ignored fallback.');
    echo "ArrayKit query-first boundary passed\n";
}

function arrayProjectionQueryRange(): void
{
    $rows = ['low' => ['amount' => -1], 'min' => ['amount' => 0], 'max' => ['amount' => 10], 'high' => ['amount' => 11], 'missing' => []];
    verify(array_keys(ArrayMulti::whereBetween($rows, 'amount', 0, 10)) === ['min', 'max'], 'Range lost inclusive bounds or included missing field.');
    verify(ArrayMulti::whereBetween($rows, 'amount', 11, 10) === [], 'Inverted range returned rows.');
    echo "ArrayKit query-range boundary passed\n";
}

function arrayProjectionQueryCallback(): void
{
    $rows = ['a' => ['ok' => true], 'b' => ['ok' => false], 'c' => ['ok' => true]];
    $calls = [];
    $filtered = ArrayMulti::whereCallback($rows, static function (array $row, string $key) use (&$calls): bool {
        $calls[] = $key;
        return $row['ok'];
    });
    verify($calls === ['a', 'b', 'c'] && $filtered === ['a' => $rows['a'], 'c' => $rows['c']], 'Predicate traversal or key retention differs.');
    verify(ArrayMulti::whereCallback([], null, 'fallback') === 'fallback' && ArrayMulti::whereCallback($rows) === $rows, 'Default predicate policy differs.');
    echo "ArrayKit query-callback boundary passed\n";
}

function arrayProjectionPartitionKeys(): void
{
    $rows = ['a' => 0, 9 => false, 'b' => null, 12 => 4];
    $calls = [];
    $parts = ArraySingle::partition($rows, static function (mixed $value, int|string $key) use (&$calls): bool {
        $calls[] = $key;
        return is_int($value);
    });
    verify($calls === ['a', 9, 'b', 12] && $parts === [['a' => 0, 12 => 4], [9 => false, 'b' => null]], 'Partition lost keys, order or types.');
    echo "ArrayKit partition-keys boundary passed\n";
}

function arrayProjectionSkipBoundary(): void
{
    $rows = ['a' => 'pending', 3 => 'pending', 'b' => 'ready', 7 => 'pending'];
    $seen = [];
    $suffix = ArraySingle::skipUntil($rows, static function (string $value, int|string $key) use (&$seen): bool {
        $seen[] = $key;
        return $value === 'ready';
    });
    verify($seen === ['a', 3, 'b'] && $suffix === ['b' => 'ready', 7 => 'pending'], 'SkipUntil reevaluated after boundary or lost suffix.');
    verify(ArraySingle::skipWhile($rows, static fn(string $value): bool => $value === 'pending') === $suffix, 'SkipWhile differs at same boundary.');
    echo "ArrayKit skip-boundary boundary passed\n";
}

function arrayProjectionRekeyCollision(): void
{
    $rows = ['old' => 0, 'other' => false, 'keep' => null];
    verify(ArraySingle::rekey($rows, ['old' => 'new', 'other' => 'new']) === ['new' => false, 'keep' => null], 'Rekey collision did not preserve last owner.');
    expectFailure(static fn() => ArraySingle::rekey($rows, static fn() => []), InvalidArgumentException::class, 'Invalid rekey callback accepted.');
    verify($rows === ['old' => 0, 'other' => false, 'keep' => null], 'Rejected rekey mutated source.');
    echo "ArrayKit rekey-collision boundary passed\n";
}

function arrayProjectionCombineKeys(): void
{
    verify(ArraySingle::combine(['a', 'a', 0], [1, false, null]) === ['a' => false, 0 => null], 'Combine key collision or null differs.');
    foreach ([[['a'], [1, 2]], [[[]], [1]]] as [$keys, $values]) {
        expectFailure(static fn() => ArraySingle::combine($keys, $values), InvalidArgumentException::class, 'Malformed combine accepted.');
    }
    verify(ArraySingle::combine([], []) === [], 'Empty combine differs.');
    echo "ArrayKit combine-keys boundary passed\n";
}

function arrayProjectionStrictSet(): void
{
    $rows = ['a' => 1, 'b' => '1', 'c' => false, 'd' => null, 'e' => 1];
    verify(ArraySingle::diff($rows, [1, null], true) === ['b' => '1', 'c' => false], 'Strict difference conflated values.');
    verify(ArraySingle::duplicates($rows, true) === [1] && ArraySingle::containsAll($rows, [false, null], true), 'Strict duplicate/membership differs.');
    verify(!ArraySingle::containsAny($rows, [1.0, '0'], true), 'Strict membership coerced a value.');
    verify(ArraySingle::symmetricDiff([1, '1'], ['1', 2], true) === [1, 2], 'Strict symmetric difference differs.');
    echo "ArrayKit strict-set boundary passed\n";
}

function arrayProjectionRecursiveSort(): void
{
    $rows = ['z' => ['z' => 3, 'a' => 1], 'a' => [3, 1, 2]];
    verify(ArrayMulti::sortRecursive($rows) === ['a' => [1, 2, 3], 'z' => ['a' => 1, 'z' => 3]], 'Recursive ascending sort lost keys.');
    verify(ArrayMulti::sortRecursive($rows, descending: true) === ['z' => ['z' => 3, 'a' => 1], 'a' => [3, 2, 1]], 'Recursive descending sort differs.');
    verify($rows['a'] === [3, 1, 2], 'Recursive sort mutated source.');
    echo "ArrayKit recursive-sort boundary passed\n";
}

function arrayProjectionSortBudget(): void
{
    $rows = ['z' => ['b' => 2, 'a' => 1], 'a' => [3, 1, 2]];
    foreach ([['maxDepth' => 1], ['maxNodes' => 2]] as $limits) {
        expectFailure(static fn() => ArrayMulti::sortRecursiveGuarded($rows, ...($limits + ['throwOnTooDeep' => true])), RuntimeException::class, 'Guarded recursive sort exceeded bound.');
    }
    verify(ArrayMulti::sortRecursiveGuarded($rows, maxDepth: 2, maxNodes: 7, throwOnTooDeep: true) === ['a' => [1, 2, 3], 'z' => ['a' => 1, 'b' => 2]], 'Exact guarded sort budget rejected.');
    echo "ArrayKit sort-budget boundary passed\n";
}

function arrayProjectionConfigRequired(): void
{
    $config = ArrayKit::config(['service' => ['nil' => null, 'off' => false, 'zero' => 0]]);
    foreach ([false, true] as $cache) {
        $config->readCache($cache);
        verify($config->getOrFail(['service.nil', 'service.off', 'service.zero']) === ['service.nil' => null, 'service.off' => false, 'service.zero' => 0], 'Required config projection lost present values.');
        expectFailure(static fn() => $config->getOrFail(['service.nil', 'service.missing']), OutOfBoundsException::class, 'Required missing value accepted.');
        verify($config->getOrFail('service.zero') === 0, 'Failed required lookup poisoned next lookup.');
    }
    echo "ArrayKit config-required boundary passed\n";
}

function arrayProjectionConfigSequence(): void
{
    $config = ArrayKit::config(['service' => ['list' => [false, null], 'scalar' => 0, 'nil' => null]]);
    $config->readCache(true);
    verify($config->getList('service.list') === [false, null], 'Initial sequence unavailable.');
    verify($config->append('service.list', 0) && $config->prepend('service.list', 'first'), 'Sequence mutation failed.');
    verify($config->getList('service.list') === ['first', false, null, 0], 'Cached sequence changed ordering or type.');
    verify($config->append('service.new', false) && $config->getList('service.new') === [false], 'Missing sequence was not initialized.');
    foreach (['service.scalar', 'service.nil'] as $key) {
        expectFailure(static fn() => $config->append($key, 1), InvalidArgumentException::class, 'Non-array sequence mutation accepted.');
    }
    verify($config->getOrFail('service.scalar') === 0 && $config->getOrFail('service.nil') === null, 'Rejected mutation altered source.');
    echo "ArrayKit config-sequence boundary passed\n";
}

function arrayProjectionConfigFileReload(): void
{
    $root = evidenceDirectory() . '/arraykit-reload';
    verify(mkdir($root, 0700), 'Reload fixture directory unavailable.');
    $valid = $root . '/valid.php';
    $invalid = $root . '/invalid.php';
    verify(file_put_contents($valid, '<?php return ["service" => ["port" => 6432, "nil" => null]];') !== false, 'Valid fixture unavailable.');
    verify(file_put_contents($invalid, '<?php return false;') !== false, 'Invalid fixture unavailable.');
    $config = ArrayKit::config(['service' => ['port' => 5432]]);
    $config->readCache(true);
    verify($config->getInt('service.port') === 5432, 'Initial cached value differs.');
    expectFailure(static fn() => $config->reload($invalid), UnexpectedValueException::class, 'Non-array reload accepted.');
    verify(!$config->reload($root . '/missing.php') && $config->getInt('service.port') === 5432, 'Failed reload changed active configuration.');
    verify($config->reload($valid) && $config->getInt('service.port') === 6432 && $config->getOrFail('service.nil') === null, 'Valid reload did not replace cached values.');
    echo "ArrayKit config-file-reload boundary passed\n";
}

function arrayProjectionConfigEnum(): void
{
    $config = ArrayKit::config(['state' => 'active', 'count' => 1, 'wrong' => '1', 'unit' => 'Ready', 'bad' => 'ready']);
    foreach ([false, true] as $cache) {
        $config->readCache($cache);
        verify($config->getEnum('state', LabArrayStringState::class) === LabArrayStringState::Active, 'String enum resolution differs.');
        verify($config->getEnum('count', LabArrayIntState::class) === LabArrayIntState::One && $config->getEnum('wrong', LabArrayIntState::class) === null, 'Integer enum was coerced.');
        verify($config->getEnum('unit', LabArrayUnitState::class) === LabArrayUnitState::Ready && $config->getEnum('bad', LabArrayUnitState::class) === null, 'Unit enum name matching differs.');
        expectFailure(static fn() => $config->getEnum('state', stdClass::class), InvalidArgumentException::class, 'Non-enum class accepted.');
    }
    echo "ArrayKit config-enum boundary passed\n";
}

function arrayProjectionConfigSnapshots(): void
{
    $config = ArrayKit::config(['service' => ['port' => 5432, 'nil' => null]]);
    $config->readCache(true);
    verify($config->snapshot('initial') && !$config->changed('initial'), 'Initial named snapshot differs.');
    verify($config->set('service.port', 6432) && $config->snapshot('next'), 'Second snapshot failed.');
    verify($config->changed('initial') && !$config->changed('next') && $config->changed('missing'), 'Named snapshots conflated state.');
    verify(!$config->restore('missing') && $config->getInt('service.port') === 6432, 'Missing snapshot changed live state.');
    verify($config->restore('initial') && $config->getInt('service.port') === 5432 && $config->getOrFail('service.nil') === null, 'Initial snapshot restore lost values.');
    verify($config->restore('next') && $config->getInt('service.port') === 6432, 'Second snapshot was mutated by restore.');
    echo "ArrayKit config-snapshots boundary passed\n";
}
