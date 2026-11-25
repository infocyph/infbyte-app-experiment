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

final class LabUnlockedMacroBoundary
{
    use MacroMix;

    private int $total = 0;

    public function total(): int
    {
        return $this->total;
    }
}

final class LabLockedMacroBoundary
{
    use MacroMix;

    public const ENABLE_LOCK = true;

    private int $total = 0;

    public function total(): int
    {
        return $this->total;
    }
}

final class LabMacroCallable
{
    public int $calls = 0;

    public function __invoke(int $value): int
    {
        ++$this->calls;

        return $value * 2;
    }

    public function touch(int $value): void
    {
        $this->calls += $value;
    }
}

function remixMacroBoundaryContracts(bool $locked, string $contract): void
{
    $class = $locked ? LabLockedMacroBoundary::class : LabUnlockedMacroBoundary::class;
    $other = $locked ? LabUnlockedMacroBoundary::class : LabLockedMacroBoundary::class;
    $one = new $class();
    $two = new $class();
    $callable = new LabMacroCallable();
    try {
        switch ($contract) {
            case 'private-binding':
                $class::macro('increase', function (int $value): void { $this->total += $value; });
                verify($one->increase(7) === $one && $two->increase(3) === $two && $one->total() === 7 && $two->total() === 3, 'Bound macro crossed instance or private scope.');
                break;
            case 'static-denial':
                $class::macro('increase', function (int $value): void { $this->total += $value; });
                expectFailure(static fn() => $class::increase(5), BadMethodCallException::class, 'Unbound static invocation admitted.');
                verify($one->total() === 0 && $one->increase(4)->total() === 4, 'Static rejection damaged callable state.');
                break;
            case 'replacement':
                $class::macro('value', function (): int { return $this->total; });
                verify($one->value() === 0, 'Initial bound closure failed.');
                $class::macro('value', static fn(): int => 15);
                verify($class::value() === 15 && $one->value() === 15, 'Replacement retained closure binding metadata.');
                $class::macro('value', $callable);
                verify($class::value(6) === 12 && $one->value(7) === 14 && $callable->calls === 2, 'Callable replacement retained stale metadata.');
                break;
            case 'invokable':
                $class::macro('double', $callable);
                verify($class::double(3) === 6 && $one->double(9) === 18 && $callable->calls === 2, 'Invokable state or result changed.');
                break;
            case 'array-callable':
                $class::macro('touch', [$callable, 'touch']);
                verify($one->touch(2) === $one && $class::touch(3) === $class && $callable->calls === 5, 'Void callable return or owner differs.');
                break;
            case 'first-class':
                $class::macro('touch', $callable->touch(...));
                expectFailure(static fn() => $one->touch(2), BadMethodCallException::class, 'Non-bindable first-class closure was rebound.');
                verify($callable->calls === 0, 'Rejected closure executed.');
                $class::macro('touch', [$callable, 'touch']);
                verify($one->touch(3) === $one && $callable->calls === 3, 'Valid callable failed after rejected closure.');
                break;
            case 'registry-isolation':
                $class::macro('value', static fn(): string => 'first');
                $other::macro('value', static fn(): string => 'second');
                verify($class::value() === 'first' && $other::value() === 'second', 'Separate consumer classes shared a registry.');
                $class::removeMacro('unknown');
                $class::removeMacro('value');
                expectFailure(static fn() => $one->value(), BadMethodCallException::class, 'Removed macro remained callable.');
                verify(!$class::hasMacro('value') && $other::value() === 'second', 'Removal crossed a consumer class.');
                break;
            case 'exception-recovery':
                $failure = new DomainException('fixture');
                $class::macro('operation', static fn() => throw $failure);
                try {
                    $one->operation();
                    throw new LogicException('Macro exception swallowed.');
                } catch (DomainException $caught) {
                    verify($caught === $failure, 'Macro exception identity changed.');
                }
                $class::macro('operation', static fn(): int => 42);
                verify($class::operation() === 42 && $one->operation() === 42, 'Macro failed after exception.');
                break;
            default:
                throw new InvalidArgumentException('Unknown macro boundary.');
        }
    } finally {
        foreach ([$class, $other] as $owner) {
            foreach (array_keys($owner::getMacros()) as $name) {
                $owner::removeMacro($name);
            }
            verify($owner::getMacros() === [], 'Macro registry cleanup failed.');
        }
    }
    echo "Remix $contract (lock " . ($locked ? 'on' : 'off') . ") passed\n";
}

final class LabFenceConstructionBoundary
{
    use Limit;

    public static bool $reject = true;

    public function __construct()
    {
        if (self::$reject) {
            throw new DomainException('constructor failure');
        }
    }
}

function intermixHelperBoundaryContracts(string $contract): void
{
    require_once getenv('PROJECT') . '/vendor/infocyph/intermix/src/functions.php';
    switch ($contract) {
        case 'fence-constructor':
            LabFenceConstructionBoundary::reset();
            expectFailure(static fn() => LabFenceConstructionBoundary::instance('failed'), DomainException::class, 'Constructor failure swallowed.');
            verify(LabFenceConstructionBoundary::countInstances() === 0 && !LabFenceConstructionBoundary::hasInstance('failed'), 'Failed construction allocated a slot.');
            LabFenceConstructionBoundary::$reject = false;
            $one = LabFenceConstructionBoundary::instance('failed');
            verify($one === LabFenceConstructionBoundary::instance('failed') && LabFenceConstructionBoundary::countInstances() === 1, 'Construction recovery failed.');
            LabFenceConstructionBoundary::reset();
            break;
        case 'fence-default-key':
            LabFenceMulti::reset();
            $one = LabFenceMulti::instance(null);
            verify($one === LabFenceMulti::instance() && $one === LabFenceMulti::instance('default') && LabFenceMulti::hasInstance(null), 'Default and null slots diverged.');
            verify(LabFenceMulti::instance('') !== $one && LabFenceMulti::getKeys() === ['default', ''], 'Explicit empty key was normalized to default.');
            LabFenceMulti::reset();
            break;
        case 'fence-limit-shrink':
            LabFenceLimit::reset();
            LabFenceLimit::setLimit(3);
            $one = LabFenceLimit::instance('one');
            LabFenceLimit::instance('two');
            LabFenceLimit::setLimit(1);
            verify(LabFenceLimit::countInstances() === 2 && LabFenceLimit::instance('one') === $one, 'Limit reduction evicted a live owner.');
            expectFailure(static fn() => LabFenceLimit::instance('three'), LimitExceededException::class, 'Reduced limit admitted another owner.');
            LabFenceLimit::clearInstances();
            LabFenceLimit::instance('new');
            expectFailure(static fn() => LabFenceLimit::instance('extra'), LimitExceededException::class, 'Clear removed the limit override.');
            LabFenceLimit::reset();
            break;
        case 'fence-late-class':
            LabFenceMulti::reset();
            $name = 'LabLateRequirementBoundary';
            $constraints = ['classes' => [$name], 'extensions' => ['JSON']];
            expectFailure(static fn() => LabFenceMulti::instance('late', $constraints), RequirementException::class, 'Missing class accepted.');
            class_alias(LabMacroCallable::class, $name);
            verify(LabFenceMulti::instance('late', $constraints) === LabFenceMulti::instance('late'), 'Negative class lookup was retained after availability changed.');
            LabFenceMulti::reset();
            break;
        case 'retry-exhaustion':
            $calls = [];
            $failures = [new RuntimeException('one'), new RuntimeException('two'), new RuntimeException('three')];
            try {
                retry(3, static function (int $attempt) use (&$calls, $failures): never {
                    $calls[] = $attempt;
                    throw $failures[$attempt - 1];
                });
                throw new LogicException('Retry exhaustion swallowed.');
            } catch (RuntimeException $caught) {
                verify($caught === $failures[2] && $calls === [1, 2, 3], 'Final error identity or attempt budget differs.');
            }
            break;
        case 'retry-backoff':
            $times = [];
            $value = retry(3, static function (int $attempt) use (&$times): int {
                $times[] = hrtime(true);
                if ($attempt < 3) {
                    throw new RuntimeException('transient');
                }

                return 42;
            }, delayMs: 10, backoff: 2.0);
            verify($value === 42 && count($times) === 3 && $times[1] - $times[0] >= 10_000_000 && $times[2] - $times[1] >= 20_000_000, 'Monotonic retry backoff was not applied.');
            break;
        case 'retry-predicate':
            $attempts = [];
            $observed = [];
            $transient = new RuntimeException('transient');
            $permanent = new DomainException('permanent');
            try {
                retry(4, static function (int $attempt) use (&$attempts, $transient, $permanent): never {
                    $attempts[] = $attempt;
                    throw $attempt === 1 ? $transient : $permanent;
                }, static function (Throwable $error) use (&$observed): bool {
                    $observed[] = $error;

                    return $error instanceof RuntimeException;
                });
                throw new LogicException('Permanent failure swallowed.');
            } catch (DomainException $caught) {
                verify($caught === $permanent && $attempts === [1, 2] && $observed === [$transient, $permanent], 'Retry predicate or error identity differs.');
            }
            break;
        case 'retry-invalid-budget':
            $called = false;
            expectFailure(static function () use (&$called): void { retry(0, static function () use (&$called): void { $called = true; }); }, InvalidArgumentException::class, 'Invalid retry budget accepted.');
            verify(!$called && retry(1, static fn(int $attempt): int => $attempt) === 1, 'Invalid budget executed work or affected next invocation.');
            break;
        default:
            throw new InvalidArgumentException('Unknown helper boundary.');
    }
    echo "InterMix helper $contract passed\n";
}

function remixCallbackContracts(string $contract): void
{
    $record = new LabConditionalRecord();
    $other = new LabConditionalRecord();
    $calls = 0;
    switch ($contract) {
        case 'null-result':
            $action = static function (LabConditionalRecord $target) use (&$calls): void {
                ++$calls;
                $target->increase(2);
            };
            verify($record->when(true, $action) === $record && $record->unless(false, $action) === $record
                && $record->value === 4 && $calls === 2, 'Null callback result lost fluent identity.');
            break;
        case 'false-result':
            verify($record->when(true, static fn(): bool => false) === false
                && $record->unless(false, static fn(): int => 0) === 0
                && $record->when(false, static fn() => throw new LogicException('Wrong branch'), static fn(): string => '') === '',
                'Falsy explicit results were replaced by target.');
            break;
        case 'predicate-once':
            $predicate = static function (LabConditionalRecord $target) use (&$calls): int {
                ++$calls;

                return $target->value;
            };
            $record->increase(3);
            verify($record->when($predicate, static fn(LabConditionalRecord $target, int $value): int => $target->increase($value)) === 6
                && $record->unless($predicate, static fn() => throw new LogicException('Wrong branch'), static fn($target, $value): int => $value) === 6
                && $calls === 2, 'Predicate evaluated repeatedly or lost its captured value.');
            break;
        case 'tap-exception':
            $failure = new DomainException('fixture');
            try {
                $record->tap(static fn() => throw $failure);
                throw new LogicException('Tap swallowed callback failure.');
            } catch (DomainException $caught) {
                verify($caught === $failure, 'Tap exception identity changed.');
            }
            verify($record->tap()->increase(4) === $record && $record->value === 4, 'Tap proxy failed after callback exception.');
            break;
        default:
            throw new InvalidArgumentException('Unknown Remix callback boundary.');
    }
    verify($other->value === 0, 'Conditional or tap callback changed unrelated owner.');
    echo "Remix $contract callback contract passed\n";
}
