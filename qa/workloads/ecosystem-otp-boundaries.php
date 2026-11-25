<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\AuthenticationStateCacheInterface;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\OTP\GenericOtp;
use Infocyph\OTP\TOTP;
use Infocyph\OTP\ValueObjects\VerificationWindow;

function otpBackendsWorkload(): void
{
    $driver = (string) getenv('DB_BACKEND');
    $dsn = $driver === 'pgsql' ? 'pgsql:host=127.0.0.1;port=5432;dbname=experiment' : 'mysql:host=127.0.0.1;port=3306;dbname=experiment';
    $options = new CacheOptions(integrityKey: random_bytes(32), failOpen: false);
    $key = random_bytes(32);
    $root = evidenceDirectory() . '/otp-state';
    verify(mkdir($root, 0700), 'OTP fixture root unavailable.');
    $factories = [
        'redis' => static fn(): Cache => Cache::redis('otp-state', options: $options),
        'file' => static fn(): Cache => Cache::file('otp-state', $root . '/file', $options),
        'sqlite' => static fn(): Cache => Cache::sqlite('otp-state', $root . '/state.sqlite', $options),
        'pdo' => static fn(): Cache => Cache::pdo('otp-state', $dsn, 'experiment', 'experiment', options: $options),
    ];
    foreach ($factories as $name => $factory) {
        $cache = $factory();
        verify($cache instanceof AuthenticationStateCacheInterface, 'Backend lacks authentication state contract.');
        $service = new GenericOtp($cache, $key, ttlSeconds: 120, maxAttempts: 4);
        $code = $service->generate('same-proof');
        unset($service, $cache);
        $results = parallelRace(static fn(): int => (int) (new GenericOtp($factory(), $key, ttlSeconds: 120, maxAttempts: 4))->verify('same-proof', $code), 'otp-' . $name);
        verify(array_sum($results) === 1, "$name replay race violated single consumption.");
        $service = new GenericOtp($factory(), $key, ttlSeconds: 120, maxAttempts: 4);
        verify(!$service->verify('same-proof', $code), "$name replacement accepted consumed code.");
        $code = $service->generate('replaced-key');
        $foreign = new GenericOtp($factory(), random_bytes(32), ttlSeconds: 120, maxAttempts: 4);
        verify(!$foreign->verify('replaced-key', $code) && $service->verify('replaced-key', $code), "$name key boundary failed.");
        $expiring = new GenericOtp($factory(), $key, ttlSeconds: 1);
        $code = $expiring->generate('expires');
        sleep(2);
        verify(!$expiring->verify('expires', $code), "$name accepted expired code.");
        echo "$name: atomic single-use, key boundary and expiry passed\n";
    }
}

function otpDriftWorkload(): void
{
    $options = new CacheOptions(integrityKey: random_bytes(32), failOpen: false);
    $cache = Cache::redis('otp-drift', options: $options);
    $timestamp = 1_800_000_000;
    foreach (['sha1', 'sha256', 'sha512'] as $algorithm) {
        foreach ([6, 8] as $digits) {
            $secret = TOTP::generateSecret(32);
            $totp = new TOTP($secret, $digits, 30, $algorithm);
            $window = new VerificationWindow(1, 1);
            $binding = "$algorithm-$digits";
            $previous = $totp->generate($timestamp - 30);
            $current = $totp->generate($timestamp);
            $future = $totp->generate($timestamp + 30);
            $result = $totp->verifyWithWindow($previous, $timestamp, $window, $cache, $binding);
            verify($result->matched && $result->driftOffset === -1, 'Past drift mismatch.');
            verify(!$totp->verifyWithWindow($previous, $timestamp, $window, $cache, $binding)->matched, 'Past code replay accepted.');
            verify($totp->verifyWithWindow($current, $timestamp, $window, $cache, $binding)->matched, 'Current step rejected.');
            $result = $totp->verifyWithWindow($future, $timestamp, $window, $cache, $binding);
            verify($result->matched && $result->driftOffset === 1, 'Future drift mismatch.');
            verify(!$totp->verifyWithWindow($current, $timestamp, $window, $cache, $binding)->matched, 'Replay state regressed.');
            verify(!$totp->verifyWithWindow('bad', $timestamp, $window)->matched, 'Malformed code accepted.');
            $next = TOTP::generateSecret(32);
            $rotation = $totp->planRotation($next, 'laboratory', 'Infbyte', 120, $timestamp);
            verify($rotation->hasGracePeriod() && $rotation->isDualSecretActive(new DateTimeImmutable('@' . ($timestamp + 119))) && !$rotation->isDualSecretActive(new DateTimeImmutable('@' . ($timestamp + 120))), 'Rotation overlap boundary mismatch.');
            verify($rotation->nextEnrollment !== null && hash_equals($rotation->nextEnrollment->secret, $next), 'Replacement enrollment mismatch.');
            $replacement = new TOTP($next, $digits, 30, $algorithm);
            verify($replacement->verifyWithWindow($replacement->generate($timestamp + 120), $timestamp + 120, new VerificationWindow())->matched, 'Replacement factor rejected.');
            expectFailure(static fn() => $totp->planRotation($secret, 'laboratory', 'Infbyte', 120, $timestamp), InvalidArgumentException::class, 'Identical secret rotation accepted.');
        }
    }
    echo "TOTP drift/replay and planned secret rotation across algorithms/digit counts passed\n";
}
