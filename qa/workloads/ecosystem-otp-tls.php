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
