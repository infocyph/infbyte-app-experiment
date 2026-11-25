<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Exceptions\CacheBackendException;
use Infocyph\OTP\GenericOtp;
use Infocyph\OTP\TOTP;
use Infocyph\OTP\ValueObjects\VerificationWindow;

function labOtpTlsCache(string $namespace, CacheOptions $options): Cache
{
    $client = labTlsRedis(getenv('LAB_CACHE_TLS_ROOT') . '/cert.pem');

    return getenv('LAB_TLS_BACKEND') === 'valkey'
        ? Cache::valkey($namespace, client: $client, options: $options)
        : Cache::redis($namespace, client: $client, options: $options);
}

function otpTlsWait(string $path): void
{
    $deadline = hrtime(true) + 120_000_000_000;
    while (!is_file($path)) {
        verify(hrtime(true) < $deadline, 'Host outage barrier timed out.');
        usleep(50_000);
    }
}

function otpTlsLiveContracts(): void
{
    $root = getenv('LAB_CACHE_TLS_ROOT');
    $options = new CacheOptions(integrityKey: random_bytes(32), failOpen: false);
    $key = random_bytes(32);
    $secret = TOTP::generateSecret(32);
    $at = intdiv(time(), 30) * 30;
    $window = new VerificationWindow(0, 0);
    $cache = labOtpTlsCache('otp-tls-live', $options);
    $generic = new GenericOtp($cache, $key, ttlSeconds: 600);
    $totp = new TOTP($secret);
    $consumed = $generic->generate('consumed');
    $pending = $generic->generate('pending');
    verify($generic->verify('consumed', $consumed), 'Generic OTP seed consumption failed.');
    verify($totp->verifyWithWindow($totp->generate($at), $at, $window, $cache, 'factor')->matched,
        'TOTP seed consumption failed.');
    verify(file_put_contents($root . '/otp-live-ready', 'ready', LOCK_EX) !== false, 'Ready marker failed.');
    otpTlsWait($root . '/otp-live-offline');
    foreach ([
        static fn(): bool => $generic->verify('pending', $pending),
        static fn(): bool => $totp->verifyWithWindow($totp->generate($at + 30), $at + 30, $window, $cache, 'factor')->matched,
    ] as $operation) {
        $admitted = false;
        try {
            $admitted = $operation();
        } catch (CacheBackendException|RedisException) {
            // The cache backend is genuinely stopped; either explicit
            // rejection or a backend exception is a fail-closed outcome.
        }
        verify(!$admitted, 'Live disconnected authentication client admitted a proof.');
    }
    verify(file_put_contents($root . '/otp-live-rejected', 'rejected', LOCK_EX) !== false, 'Rejection marker failed.');
    otpTlsWait($root . '/otp-live-recovered');

    $fresh = labOtpTlsCache('otp-tls-live', $options);
    $replacement = new GenericOtp($fresh, $key, ttlSeconds: 600);
    verify(!$replacement->verify('consumed', $consumed), 'Durable consumed proof resurrected.');
    verify(!$totp->verifyWithWindow($totp->generate($at), $at, $window, $fresh, 'factor')->matched,
        'Durable replay cursor regressed.');
    verify($replacement->verify('pending', $pending) && !$replacement->verify('pending', $pending),
        'Rejected offline proof did not settle once after restart.');
    $next = $totp->generate($at + 30);
    verify($totp->verifyWithWindow($next, $at + 30, $window, $fresh, 'factor')->matched
        && !$totp->verifyWithWindow($next, $at + 30, $window, $fresh, 'factor')->matched,
        'Rejected offline TOTP did not settle once after restart.');
    echo "Live TLS authentication connection failed closed and durable state recovered\n";
}

function otpTlsOfflineAdmission(): void
{
    $rejected = false;
    try {
        $cache = labOtpTlsCache('otp-election', new CacheOptions(integrityKey: str_repeat('i', 32), failOpen: false));
        $service = new GenericOtp($cache, random_bytes(32), ttlSeconds: 600);
        $service->generate('offline');
    } catch (CacheBackendException|RedisException|RuntimeException) {
        $rejected = true;
    }
    verify($rejected, 'Stopped TLS authentication backend admitted new state.');
    echo "Fresh TLS authentication admission failed while service was stopped\n";
}

function otpTlsConnectedFactorContracts(string $factor): void
{
    $root = (string) getenv('LAB_CACHE_TLS_ROOT');
    $options = new CacheOptions(integrityKey: random_bytes(32), failOpen: false);
    $factory = static fn(): Cache => labOtpTlsCache('otp-tls-factor', $options);
    $cache = $factory();
    $key = random_bytes(32);
    $secret = TOTP::generateSecret(32);
    $at = intdiv(time(), 30) * 30;
    $window = new VerificationWindow(0, 0);
    $service = match ($factor) {
        'hotp' => new \Infocyph\OTP\HOTP($secret),
        'mobile' => new \Infocyph\OTP\MobileOTP(substr(bin2hex($key), 0, 16), '1234'),
        'ocra-counter' => new \Infocyph\OTP\OCRA('OCRA-1:HOTP-SHA256-8:C-QN08', $key),
        'ocra-challenge' => new \Infocyph\OTP\OCRA('OCRA-1:HOTP-SHA256-8:QN08', $key),
        default => throw new InvalidArgumentException('Unknown connected TLS factor.'),
    };
    $admit = static function (Cache $store, bool $next, string $owner = 'factor') use ($factor, $service, $at, $window): bool {
        $counter = $next ? 43 : 42;
        $time = $at + ($next ? 30 : 0);
        $question = $next ? '87654321' : '12345678';

        return match ($factor) {
            'hotp' => $service->verifyWithResult($service->generate($counter), counter: $counter, cache: $store, factorId: $owner)->matched,
            'mobile' => $service->verifyWithWindow($service->generate($time), $time, $window, cache: $store, factorId: $owner)->matched,
            'ocra-counter' => $service->verifyWithResult($service->generate($question, counter: $counter), $question, counter: $counter, cache: $store, factorId: $owner)->matched,
            'ocra-challenge' => $service->verifyWithResult($service->generate($question), $question, cache: $store, factorId: $owner, replayTtl: 600)->matched,
        };
    };
    verify($admit($cache, false) && !$admit($cache, false), 'Initial connected factor replay admission failed.');
    verify(touch($root . '/otp-live-ready'), 'TLS factor readiness failed.');
    otpTlsWait($root . '/otp-live-offline');
    foreach ([false, true] as $next) {
        $admitted = false;
        try {
            $admitted = $admit($cache, $next);
        } catch (CacheBackendException|RedisException) {
            // Native disconnected cache errors are an explicit fail-closed outcome.
        }
        verify(!$admitted, 'Disconnected TLS factor admitted a proof.');
    }
    verify(touch($root . '/otp-live-rejected'), 'TLS factor outage observation failed.');
    otpTlsWait($root . '/otp-live-recovered');
    $replacement = $factory();
    verify(!$admit($replacement, false), 'Durable replay cursor regressed.');
    $results = parallelRace(static fn(): int => (int) $admit($factory(), true), 'otp-tls-connected-factor', 12);
    verify(array_sum($results) === 1 && !$admit($replacement, true), 'Recovered proof did not settle exactly once.');
    verify($admit($replacement, true, 'foreign') && !$admit($replacement, true, 'foreign'), 'Recovered factor crossed account identity.');
    echo "Connected $factor TLS outage and concurrent replay recovery passed\n";
}
