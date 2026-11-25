<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Config\Config;
use Infocyph\ArrayKit\Config\EnvParser;
use Infocyph\ArrayKit\Config\Support\Environment;
use Infocyph\InterMix\Exceptions\LimitExceededException;
use Infocyph\InterMix\Exceptions\RequirementException;
use Infocyph\InterMix\Fence\Limit;
use Infocyph\InterMix\Fence\Multi;
use Infocyph\InterMix\Fence\Single;
use Infocyph\InterMix\Remix\MacroMix;
use Infocyph\UID\Exceptions\FileLockException;
use Infocyph\UID\Exceptions\SequenceTimestampException;
use Infocyph\UID\Sequence\FilesystemSequenceProvider;

function uidFilesystemWorkload(): void
{
    $root = evidenceDirectory() . '/uid-reservations';
    verify(mkdir($root, 0700), 'Sequence directory unavailable.');
    $time = 1800000000;
    $provider = new FilesystemSequenceProvider($root, 'lab', 250000, 8);
    verify($provider->next('order', 1, $time) === 1, 'Initial allocation mismatch.');
    $results = parallelRace(static function () use ($provider, $time): array {
        $values = [];
        for ($i = 0; $i < 25; ++$i) {
            $values[] = $provider->next('order', 1, $time);
        }

        return $values;
    }, 'uid-fork-reservation');
    $values = [1, ...array_merge(...$results)];
    verify(count($values) === 301 && count(array_unique($values)) === 301, 'Inherited reservation reused after fork.');
    verify(min(array_merge(...$results)) >= 9, 'Child reused parent reservation.');
    $replacement = new FilesystemSequenceProvider($root, 'lab', 250000, 8);
    verify($replacement->next('order', 1, $time) > max($values), 'Replacement recycled unused reserved values.');
    expectFailure(static fn() => $replacement->next('order', 1, $time - 1), SequenceTimestampException::class, 'Clock regression accepted.');
    verify($replacement->next('order', 1, $time + 1) === 1, 'New timestamp did not reset allocation.');
    $state = $root . '/uid-lab-limit-2.seq';
    verify(file_put_contents($state, $time . ',' . (PHP_INT_MAX - 3)) !== false, 'Exhaustion fixture failed.');
    expectFailure(static fn() => $replacement->next('limit', 2, $time), FileLockException::class, 'Reservation overflow accepted.');
    verify(file_get_contents($state) === $time . ',' . (PHP_INT_MAX - 3), 'Overflow mutated state.');
    foreach (['malformed', str_repeat('9', 65), $time . ',9223372036854775808'] as $invalid) {
        verify(file_put_contents($state, $invalid) !== false, 'Corruption fixture failed.');
        expectFailure(static fn() => $replacement->next('limit', 2, $time), FileLockException::class, 'Malformed sequence accepted.');
        verify(file_get_contents($state) === $invalid, 'Malformed sequence silently overwritten.');
    }
    $target = $root . '/external';
    verify(file_put_contents($target, 'untouched') !== false && symlink($target, $root . '/uid-lab-link-1.seq'), 'Symlink fixture failed.');
    expectFailure(static fn() => $replacement->next('link', 1, $time), FileLockException::class, 'Sequence symlink accepted.');
    verify(file_get_contents($target) === 'untouched', 'Sequence escaped its directory.');
    $single = new FilesystemSequenceProvider($root, 'locked', 100000);
    $lock = fopen($root . '/uid-locked-order-1.seq', 'c+');
    verify(is_resource($lock) && flock($lock, LOCK_EX), 'Lock fixture failed.');
    try {
        expectFailure(static fn() => $single->next('order', 1, $time), FileLockException::class, 'Lock timeout not enforced.');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    verify($single->next('order', 1, $time) === 1, 'Allocation failed after releasing lock.');
    echo "Fork reservations, replacement gaps, clock, exhaustion, corruption, symlink and lock timeout passed\n";
}

function configReferenceWorkload(): void
{
    $root = evidenceDirectory() . '/config-reference';
    verify(mkdir($root, 0700), 'Configuration directory unavailable.');
    $source = <<<'ENV'
LAB_HOST=database
LAB_URL="https://$LAB_HOST/api"
LAB_DEFAULT=${LAB_NONEXISTENT_FIXTURE:-fallback}
LAB_LITERAL='${LAB_HOST}'
ENV;
    $parsed = EnvParser::parse($source);
    verify($parsed['LAB_URL'] === 'https://database/api' && $parsed['LAB_DEFAULT'] === 'fallback' && $parsed['LAB_LITERAL'] === '${LAB_HOST}', 'Dotenv resolution mismatch.');
    verify(EnvParser::parseRaw($source)['LAB_URL'] === 'https://$LAB_HOST/api', 'Raw parser resolved reference.');
    foreach (["\xEF\xBB\xBFKEY=value", "KEY=x\0y", 'KEY=${UNCLOSED', "A=\${B}\nB=\${A}"] as $invalid) {
        expectFailure(static fn() => EnvParser::parse($invalid), UnexpectedValueException::class, 'Invalid dotenv accepted.');
    }
    verify(file_put_contents($root . '/.env', $source) !== false, 'Dotenv fixture write failed.');
    $config = new Config();
    verify($config->loadEnvFile($root . '/.env') && !$config->loadArray(['unexpected' => true]), 'Load-only-empty policy failed.');
    verify($config->get('LAB_URL') === $parsed['LAB_URL'], 'File parse diverged.');
    $external = 7;
    $config->replace(['app' => ['value' => &$external, 'nil' => null, 'enabled' => false], 'list' => ['auth']]);
    $config->snapshot('before');
    $external = 9;
    verify($config->restore('before') && $config->get('app.value') === 7, 'Snapshot retained external reference.');
    $config->append('list', 'throttle');
    $config->prepend('list', 'cors');
    verify($config->getList('list') === ['cors', 'auth', 'throttle'], 'List mutation changed ordering.');
    verify($config->getOrFail('app.nil') === null && $config->getBool('app.enabled') === false, 'Null/false presence lost.');
    expectFailure(static fn() => $config->getOrFail('missing'), OutOfBoundsException::class, 'Missing path accepted.');
    expectFailure(static fn() => $config->append('app.value', 8), InvalidArgumentException::class, 'Scalar list mutation accepted.');
    $cycle = [];
    $cycle['self'] = &$cycle;
    $config->replace($cycle);
    expectFailure(static fn() => $config->snapshot('before'), UnexpectedValueException::class, 'Cyclic snapshot accepted.');
    verify($config->restore('before') && $config->get('app.value') === 7, 'Failed snapshot overwrote valid snapshot.');
    $_ENV['LAB_CONFIG_REFERENCE'] = 'generation-one';
    try {
        $config->replace(['ref' => Environment::ref('LAB_CONFIG_REFERENCE')]);
        verify($config->exportCache($root . '/compiled.php'), 'Configuration cache export failed.');
        $_ENV['LAB_CONFIG_REFERENCE'] = 'generation-two';
        $fresh = new Config();
        verify($fresh->loadCache($root . '/compiled.php') && $fresh->get('ref') === 'generation-one', 'Compiled generation changed with runtime environment.');
        verify($config->exportCache($root . '/compiled.php'), 'Configuration rebuild failed.');
        $fresh = new Config();
        verify($fresh->loadCache($root . '/compiled.php') && $fresh->get('ref') === 'generation-two', 'Compiled replacement did not observe environment.');
    } finally {
        unset($_ENV['LAB_CONFIG_REFERENCE']);
    }
    echo "Dotenv raw/resolved parsing, invalid references, snapshots, list boundaries and cache generations passed\n";
}

final class LabFenceSingle { use Single; }
final class LabFenceMulti { use Multi; }
final class LabFenceLimit { use Limit; }
final class LabMacroRecord
{
    use MacroMix;
    public int $amount = 0;
}

function fenceRemixWorkload(): void
{
    $first = LabFenceSingle::instance('first', ['extensions' => ['json'], 'classes' => [PDO::class]]);
    verify(LabFenceSingle::instance('second') === $first && LabFenceSingle::getKeys() === ['__single'], 'Singleton keyed incorrectly.');
    LabFenceSingle::reset();
    verify(LabFenceSingle::instance() !== $first, 'Singleton reset retained old instance.');
    $one = LabFenceMulti::instance('one');
    verify(LabFenceMulti::instance('one') === $one && LabFenceMulti::instance('two') !== $one, 'Multiton identity failed.');
    expectFailure(static fn() => LabFenceMulti::instance('missing', ['classes' => ['LabMissingFixtureClass']]), RequirementException::class, 'Missing requirement accepted.');
    verify(!LabFenceMulti::hasInstance('missing') && LabFenceMulti::countInstances() === 2, 'Rejected instance mutated registry.');
    LabFenceLimit::reset();
    LabFenceLimit::instance('one');
    LabFenceLimit::instance('two');
    expectFailure(static fn() => LabFenceLimit::instance('three'), LimitExceededException::class, 'Default limit bypassed.');
    LabFenceLimit::setLimit(3);
    LabFenceLimit::instance('three');
    verify(LabFenceLimit::countInstances() === 3, 'Limit override ignored.');
    LabFenceLimit::reset();
    verify(LabFenceLimit::countInstances() === 0, 'Reset failed.');
    expectFailure(static fn() => LabFenceLimit::setLimit(0), InvalidArgumentException::class, 'Invalid limit accepted.');
    LabMacroRecord::macro('add', function (int $amount): void { $this->amount += $amount; });
    LabMacroRecord::macro('unit', static fn(): string => 'BDT');
    $record = new LabMacroRecord();
    verify($record->add(7)->add(9) === $record && $record->amount === 16 && LabMacroRecord::unit() === 'BDT', 'Macro binding/static/chaining failed.');
    verify((new LabMacroRecord())->amount === 0, 'Macro leaked per-instance state.');
    LabMacroRecord::removeMacro('add');
    expectFailure(static fn() => $record->add(1), BadMethodCallException::class, 'Removed macro remained callable.');
    LabMacroRecord::removeMacro('unit');
    require_once getenv('PROJECT') . '/vendor/infocyph/intermix/src/functions.php';
    verify(pipe([1, 2, 3], array_sum(...)) === 6 && when(false, static fn() => 1, static fn() => 0) === 0, 'Remix transforms failed.');
    verify(retry(3, static fn(int $attempt) => $attempt < 3 ? throw new RuntimeException('transient') : 42) === 42, 'Retry success boundary failed.');
    $attempts = 0;
    expectFailure(static function () use (&$attempts): void {
        retry(3, static function () use (&$attempts): never {
            ++$attempts;
            throw new DomainException('permanent');
        }, static fn(Throwable $failure): bool => false);
    }, DomainException::class, 'Permanent failure swallowed.');
    verify($attempts === 1, 'Permanent operation retried.');
    $elapsed = null;
    verify(measure(static fn(): int => 42, $elapsed) === 42 && is_float($elapsed) && $elapsed >= 0, 'Measure contract failed.');
    echo "Fence identity, requirements, limits/reset and Remix macros, callbacks and retry boundaries passed\n";
}


final class LabConditionalRecord
{
    use \Infocyph\InterMix\Remix\ConditionableTappable;

    public int $value = 0;

    public function increase(int $amount): int
    {
        return $this->value += $amount;
    }

    public function positive(): bool
    {
        return $this->value > 0;
    }
}

final class LabMacroMixSource
{
    /** @Macro("sourceValue") */
    public function read(): int
    {
        return 42;
    }

    protected static function double(int $value): int
    {
        return $value * 2;
    }

    private function hidden(): int
    {
        return 99;
    }
}

function remixCompositionWorkload(): void
{
    $record = new LabConditionalRecord();
    verify($record->when(false)->increase(10) === $record && $record->value === 0, 'False proxy invoked target.');
    verify($record->unless()->positive()->increase(3) === 3, 'Deferred inverted predicate failed.');
    verify($record->when()->positive()->increase(4) === 7, 'Deferred positive predicate failed.');
    verify($record->when()->value->increase(1) === 8, 'Deferred property predicate failed.');
    $record->when(false)->value = 100;
    $record->when(true)->value = 9;
    verify($record->value === 9 && isset($record->when(true)->value) && !isset($record->when(false)->value), 'Conditional property access failed.');
    $proxy = $record->when(true);
    unset($proxy->value);
    verify($record->value === 9, 'Conditional proxy deleted target property.');
    verify($record->tap()->increase(2) === $record && $record->value === 11, 'Tap lost target identity.');
    verify($record->when(static fn(LabConditionalRecord $r): bool => $r->positive(), static fn(LabConditionalRecord $r): int => $r->increase(1)) === 12, 'Closure predicate failed.');
    verify($record->unless(true, static fn() => throw new LogicException('Wrong branch'), static fn(LabConditionalRecord $r): int => $r->increase(2)) === 14, 'Unless default branch failed.');

    $source = new LabMacroMixSource();
    LabMacroRecord::loadMacrosFromConfig(['double' => static fn(int $value): int => $value + 1]);
    LabMacroRecord::mix($source, replace: false);
    verify(LabMacroRecord::double(4) === 5 && !LabMacroRecord::hasMacro('hidden'), 'Non-replacing mix changed registry.');
    LabMacroRecord::mix($source, replace: true);
    LabMacroRecord::loadMacrosFromAnnotations($source);
    verify(LabMacroRecord::double(4) === 8 && LabMacroRecord::sourceValue() === 42 && LabMacroRecord::hasMacro('read'), 'Mixin or annotation registration failed.');
    $before = LabMacroRecord::getMacros();
    expectFailure(static fn() => LabMacroRecord::mix(LabMacroMixSource::class), InvalidArgumentException::class, 'Unbound instance mixin accepted.');
    expectFailure(static fn() => LabMacroRecord::loadMacrosFromAnnotations(LabMacroMixSource::class), InvalidArgumentException::class, 'Unbound annotation accepted.');
    verify(LabMacroRecord::getMacros() === $before, 'Rejected mixin partially changed registry.');
    foreach (array_keys($before) as $name) {
        LabMacroRecord::removeMacro($name);
    }
    verify(LabMacroRecord::getMacros() === [], 'Macro registry cleanup failed.');

    LabFenceLimit::reset();
    LabFenceLimit::setLimit(3);
    LabFenceLimit::instance('one');
    LabFenceLimit::clearInstances();
    foreach (['one', 'two', 'three'] as $key) {
        LabFenceLimit::instance($key);
    }
    verify(count(LabFenceLimit::getInstances()) === 3 && LabFenceLimit::getKeys() === ['one', 'two', 'three'], 'Clear changed override or ordering.');
    LabFenceLimit::reset();
    foreach ([['classes' => 'invalid'], ['extensions' => [123]]] as $constraints) {
        expectFailure(static fn() => LabFenceLimit::instance('bad', $constraints), InvalidArgumentException::class, 'Malformed requirement accepted.');
    }
    expectFailure(static fn() => LabFenceLimit::instance('bad', ['extensions' => ['lab_missing_extension']]), RequirementException::class, 'Missing extension accepted.');
    verify(LabFenceLimit::countInstances() === 0, 'Invalid requirements published an instance.');
    echo "Conditional and tap proxies, macro mix and annotation atomicity, Fence requirement and override lifecycle passed\n";
}
