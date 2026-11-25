<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\CacheLayer\Counter\Exception\AtomicCounterException;
use Infocyph\OTP\GenericOtp;
use Infocyph\OTP\HOTP;
use Infocyph\OTP\TOTP;
use Infocyph\OTP\ValueObjects\VerificationWindow;

function counterContractsWorkload(): void
{
    $namespace = 'lab-counter-contract';
    foreach (['redis' => 6379, 'valkey' => 6380] as $backend => $port) {
        $dsn = "$backend://127.0.0.1:$port";
        $factory = static fn(): AtomicCounterStoreInterface => $backend === 'redis'
            ? AtomicCounters::redis($namespace, $dsn)
            : AtomicCounters::valkey($namespace, $dsn);
        $counters = $factory();
        $client = new Redis();
        verify($client->connect('127.0.0.1', $port, 2.0), 'Counter backend unavailable.');
        $prefix = "cachelayer:counter:$namespace:";
        verify($counters->get('missing') === null, 'Absent counter not null.');
        $first = $counters->increment('window', 3, 60);
        $ttl = $client->pttl($prefix . 'window');
        $second = $counters->increment('window', 4, 3600);
        verify($first->initialized && $first->value === 3 && !$second->initialized && $second->value === 7, 'Counter initialization mismatch.');
        verify($ttl > 0 && $client->pttl($prefix . 'window') <= $ttl, 'Fixed TTL was extended.');
        verify($counters->decrement('window', 8)->value === -1, 'Signed decrement failed.');
        $persistent = $counters->increment('persistent', 1);
        verify($persistent->initialized && $client->ttl($prefix . 'persistent') === -1, 'Persistent counter unexpectedly expires.');
        $counters->increment('persistent', 1, 60);
        verify($client->ttl($prefix . 'persistent') === -1, 'Existing persistent counter acquired TTL.');
        $cache = $backend === 'redis' ? Cache::redis($namespace, $dsn) : Cache::valkey($namespace, $dsn);
        verify($cache->set('window', 42, 60) && $cache->clear() && $counters->get('window') === -1, 'Normal cache clear reset counter namespace.');

        $large = 9_007_199_254_740_993;
        verify($counters->increment('precision', $large)->value === $large, 'Initial large integer rounded.');
        verify($counters->increment('precision', 1)->value === $large + 1 && $counters->get('precision') === $large + 1, 'Lua integer precision lost.');
        verify($counters->increment('limit', PHP_INT_MAX)->value === PHP_INT_MAX, 'Maximum counter rounded.');
        expectFailure(static fn() => $counters->increment('limit'), AtomicCounterException::class, 'Integer overflow accepted.');
        verify($counters->get('limit') === PHP_INT_MAX, 'Overflow mutated existing counter.');
        verify($client->set($prefix . 'malformed', 'not-an-integer'), 'Malformed fixture seed failed.');
        expectFailure(static fn() => $counters->get('malformed'), AtomicCounterException::class, 'Malformed counter accepted.');
        foreach ([
            static fn() => $counters->increment('window', 0),
            static fn() => $counters->decrement('window', -1),
            static fn() => $counters->increment('window', 1, 0),
            static fn() => $counters->get('invalid key'),
        ] as $invalid) {
            expectFailure($invalid, AtomicCounterException::class, 'Invalid counter input accepted.');
        }

        $results = parallelRace(static function () use ($factory): int {
            $store = $factory();
            $initializations = 0;
            for ($i = 0; $i < 50; ++$i) {
                $initializations += (int) $store->increment('race', 2, 120)->initialized;
                $initializations += (int) $store->decrement('race', 1, 120)->initialized;
            }

            return $initializations;
        }, 'counter-' . $backend, 12);
        verify(array_sum($results) === 1 && $factory()->get('race') === 600, 'Concurrent counter reconciliation failed.');
        verify($counters->delete('race') && $counters->get('race') === null, 'Counter delete failed.');
        $counters->increment('expiry', 1, 1);
        sleep(2);
        verify($counters->get('expiry') === null && $counters->increment('expiry', 1, 60)->initialized, 'Counter expiry/reinitialization failed.');
        echo "$backend counter TTL, precision, overflow, namespace and 1200 atomic changes passed\n";
    }
}

function otpAdditionalBackendWorkload(string $backend): void
{
    $options = new CacheOptions(integrityKey: random_bytes(32), allowClosures: false, allowObjects: false, failOpen: false);
    $root = (string) getenv('RUNNER_TEMP') . '/otp-phpfiles-state';
    $driver = (string) getenv('DB_BACKEND');
    $dsn = $driver === 'pgsql' ? 'pgsql:host=127.0.0.1;port=5432;dbname=experiment' : 'mysql:host=127.0.0.1;port=3306;dbname=experiment';
    $factory = match ($backend) {
        'redis' => static fn(): Cache => Cache::redis('lab-otp-extra', options: $options),
        'file' => static fn(): Cache => Cache::file('lab-otp-extra', $root . '/file', $options),
        'sqlite' => static fn(): Cache => Cache::sqlite('lab-otp-extra', $root . '/state.sqlite', $options),
        'pdo' => static fn(): Cache => Cache::pdo('lab-otp-extra', $dsn, 'experiment', 'experiment', options: $options),
        'valkey' => static fn(): Cache => Cache::valkey('lab-otp-extra', 'valkey://127.0.0.1:6380', options: $options),
        'phpfiles' => static fn(): Cache => Cache::phpFiles('lab-otp-extra', $root, $options),
        default => throw new InvalidArgumentException('Unexpected OTP backend.'),
    };
    verify(is_dir($root) || mkdir($root, 0700, true), 'OTP state root unavailable.');
    $key = random_bytes(32);
    $service = new GenericOtp($factory(), $key, ttlSeconds: 120, maxAttempts: 4);
    $proof = $service->generate('consume-once');
    $results = parallelRace(static fn(): int => (int) (new GenericOtp($factory(), $key, ttlSeconds: 120, maxAttempts: 4))->verify('consume-once', $proof), 'otp-extra-consume', 12);
    verify(array_sum($results) === 1, 'Shared challenge consumed more than once.');
    $proof = $service->generate('attempts');
    $wrong = ($proof[0] === '0' ? '1' : '0') . substr($proof, 1);
    $results = parallelRace(static fn(): int => (int) (new GenericOtp($factory(), $key, ttlSeconds: 120, maxAttempts: 4))->verify('attempts', $wrong), 'otp-extra-attempts', 4);
    verify(array_sum($results) === 0 && !$service->verify('attempts', $proof), 'Concurrent wrong attempts did not exhaust challenge.');

    $hotp = new HOTP(HOTP::generateSecret());
    $code = $hotp->generate(42);
    $results = parallelRace(static fn(): int => (int) $hotp->verifyWithResult($code, counter: 42, cache: $factory(), factorId: 'hotp-generation-one')->matched, 'otp-extra-hotp', 12);
    verify(array_sum($results) === 1, 'HOTP accepted replay under contention.');
    verify(!$hotp->verifyWithResult($hotp->generate(41), counter: 41, cache: $factory(), factorId: 'hotp-generation-one')->matched, 'HOTP monotonic counter regressed.');
    verify($hotp->verifyWithResult($code, counter: 42, cache: $factory(), factorId: 'hotp-generation-two')->matched, 'New factor generation was not isolated.');
    $maximum = $hotp->verifyWithResult($hotp->generate(PHP_INT_MAX), counter: PHP_INT_MAX, cache: $factory(), factorId: 'exhausted');
    verify($maximum->matched && $maximum->nextCounter === null, 'Exhausted counter returned unsafe successor.');

    $totp = new TOTP(TOTP::generateSecret());
    $timestamp = 1_800_000_000;
    $code = $totp->generate($timestamp);
    $results = parallelRace(static fn(): int => (int) $totp->verifyWithWindow($code, $timestamp, new VerificationWindow(), $factory(), 'totp-shared')->matched, 'otp-extra-totp', 12);
    verify(array_sum($results) === 1, 'TOTP replay admitted concurrently.');
    verify(!$totp->verifyWithWindow($totp->generate($timestamp - 30), $timestamp - 30, new VerificationWindow(), $factory(), 'totp-shared')->matched, 'TOTP replay state regressed.');
    echo "$backend challenge exhaustion, HOTP generations/exhaustion and TOTP monotonic replay passed\n";
}


function counterElectionWorkload(bool $seed): void
{
    $backend = (string) getenv('LAB_COUNTER_BACKEND');
    $port = $seed ? 17389 : 17390;
    $client = new Redis();
    verify($client->connect('127.0.0.1', $port, 2.0), 'Counter election endpoint unavailable.');
    $store = match ($backend) {
        'redis' => AtomicCounters::redis('lab-election', client: $client),
        'valkey' => AtomicCounters::valkey('lab-election', client: $client),
        default => throw new InvalidArgumentException('Unexpected election backend.'),
    };
    $key = 'cachelayer:counter:lab-election:window';
    if ($seed) {
        verify($store->increment('window', 100, 600)->initialized, 'Initial counter not created.');
        verify($store->increment('window', 25)->value === 125 && $store->increment('persistent', 12)->value === 12, 'Seed counter values differ.');
        verify($client->rawCommand('WAIT', '1', '10000') === 1, 'Counter writes not acknowledged by replica.');
        $ttl = $client->pttl($key);
        verify($ttl > 0 && file_put_contents(evidenceDirectory() . '/counter-election-ttl', (string) $ttl, LOCK_EX) !== false, 'Counter TTL evidence unavailable.');
    } else {
        $oldTtl = (int) file_get_contents(evidenceDirectory() . '/counter-election-ttl');
        $ttl = $client->pttl($key);
        verify($store->get('window') === 125 && $store->get('persistent') === 12 && $ttl > 0 && $ttl <= $oldTtl, 'Promoted replica lost confirmed state or extended TTL.');
        $result = $store->increment('window', 5, 3600);
        verify(!$result->initialized && $result->value === 130 && $client->pttl($key) <= $ttl, 'Promoted counter reset existing window.');
        $result = $store->decrement('persistent', 2, 60);
        verify(!$result->initialized && $result->value === 10 && $client->ttl('cachelayer:counter:lab-election:persistent') === -1, 'Promoted persistent counter changed semantics.');
        $cache = $backend === 'redis'
            ? Cache::redis('lab-election', "redis://127.0.0.1:$port")
            : Cache::valkey('lab-election', "valkey://127.0.0.1:$port");
        verify($cache->set('window', 'unrelated') && $cache->clear() && $store->get('window') === 130, 'Promoted namespace isolation failed.');
        $results = parallelRace(static function () use ($backend, $port): int {
            $next = $backend === 'redis' ? AtomicCounters::redis('lab-election', "redis://127.0.0.1:$port") : AtomicCounters::valkey('lab-election', "valkey://127.0.0.1:$port");
            $initialized = 0;
            for ($i = 0; $i < 25; ++$i) {
                $initialized += (int) $next->increment('post-election')->initialized;
            }
            return $initialized;
        }, 'counter-election', 8);
        verify(array_sum($results) === 1 && $store->get('post-election') === 200, 'Promoted primary lost concurrent increments.');
    }
    echo "$backend counter election " . ($seed ? 'seed' : 'verification') . " passed\n";
}
