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
    $factory = match ($backend) {
        'valkey' => static fn(): Cache => Cache::valkey('lab-otp-extra', 'valkey://127.0.0.1:6380', options: $options),
        'phpfiles' => static fn(): Cache => Cache::phpFiles('lab-otp-extra', $root, $options),
        default => throw new InvalidArgumentException('Unexpected OTP backend.'),
    };
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
