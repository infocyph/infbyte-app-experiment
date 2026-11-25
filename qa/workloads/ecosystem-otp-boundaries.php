<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\AuthenticationStateCacheInterface;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\OTP\GenericOtp;
use Infocyph\OTP\TOTP;
use Infocyph\OTP\HOTP;
use Infocyph\OTP\OCRA;
use Infocyph\OTP\Support\ProvisioningUriParser;
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

function otpEnrollmentWorkload(): void
{
    $secret = TOTP::generateSecret(32);
    $next = TOTP::generateSecret(32);
    $label = 'account + lab@example.test';
    $issuer = 'Infbyte Lab';
    $now = 1_800_000_000;
    $extra = ['tenant' => 'alpha & beta', 'generation' => 7];
    $suite = 'OCRA-1:HOTP-SHA256-8:C-QN08';
    $cache = Cache::redis('otp-enrollment', options: new CacheOptions(failOpen: false));
    $cases = [
        'totp' => [new TOTP($secret, 8, 45, 'sha256'), ['period' => 45], []],
        'hotp' => [new HOTP($secret, 8, 'sha256'), ['counter' => 42], ['initialCounter' => 42]],
        'ocra' => [OCRA::fromBase32($suite, $secret), ['ocraSuite' => $suite], []],
    ];
    foreach ($cases as $type => [$factor, $expected, $options]) {
        $payload = $factor->getEnrollmentPayload($label, $issuer, ...$options, additionalParameters: $extra, withQrSvg: true);
        $parsed = ProvisioningUriParser::parse($payload->uri);
        verify($parsed->type === $type && $parsed->secret === $secret && $parsed->label === $label && $parsed->issuer === $issuer, 'Enrollment identity mismatch.');
        verify($parsed->digits === 8 && $parsed->algorithm === 'sha256' && $parsed->additionalParameters === ['tenant' => 'alpha & beta', 'generation' => '7'], 'Enrollment algorithm or extension mismatch.');
        foreach ($expected as $key => $value) {
            verify($parsed->$key === $value, 'Type-specific enrollment metadata mismatch.');
        }
        verify(is_string($payload->qrSvg) && str_contains($payload->qrSvg, '<svg'), 'QR rendering failed.');
        verify($payload->__debugInfo()['secret'] === '[redacted]' && $payload->__debugInfo()['uri'] === '[redacted]' && $parsed->__debugInfo()['secret'] === '[redacted]', 'Enrollment debug leaked material.');
        foreach (['&secret=' . $secret, '&Secret=' . $secret, '&bad=%ZZ', '#fragment', '&issuer=foreign'] as $suffix) {
            expectFailure(static fn() => ProvisioningUriParser::parse($payload->uri . $suffix), InvalidArgumentException::class, 'Malformed provisioning accepted.');
        }
        foreach (['SECRET', 'issuer', 'digits', 'ocraSuite'] as $key) {
            expectFailure(static fn() => $factor->getEnrollmentPayload($label, $issuer, ...$options, additionalParameters: [$key => 'invalid']), InvalidArgumentException::class, 'Reserved parameter overwritten.');
        }
        expectFailure(static fn() => $factor->getEnrollmentPayload('bad:label', $issuer, ...$options), InvalidArgumentException::class, 'Invalid label accepted.');
        expectFailure(static fn() => $factor->getEnrollmentPayload($label, $issuer, ...$options, withQrSvg: true, imageSize: 63), InvalidArgumentException::class, 'Invalid QR size accepted.');
        foreach ([null, 0, 60] as $grace) {
            $rotation = $factor->planRotation($next, $label, $issuer, ...$options, gracePeriodInSeconds: $grace, now: $now, additionalParameters: $extra);
            verify($rotation->requiresImmediateCutover() === ($grace !== 60), 'Rotation cutover mismatch.');
            verify($rotation->isDualSecretActive(new DateTimeImmutable('@' . ($now + 59))) === ($grace === 60) && !$rotation->isDualSecretActive(new DateTimeImmutable('@' . ($now + 60))), 'Rotation overlap boundary mismatch.');
            verify($rotation->nextEnrollment !== null && ProvisioningUriParser::parse($rotation->nextEnrollment->uri)->secret === $next, 'Replacement enrollment lost secret.');
            verify($rotation->__debugInfo()['currentSecret'] === '[redacted]' && $rotation->__debugInfo()['nextSecret'] === '[redacted]', 'Rotation debug leaked secret.');
        }
        expectFailure(static fn() => $factor->planRotation($secret, $label, $issuer, ...$options), InvalidArgumentException::class, 'Identical rotation accepted.');
        expectFailure(static fn() => $factor->planRotation($next, $label, $issuer, ...$options, gracePeriodInSeconds: 2, now: PHP_INT_MAX), InvalidArgumentException::class, 'Rotation timestamp overflow accepted.');
        $verify = match ($type) {
            'totp' => static fn(string $key, string $id): bool => (new TOTP($key, 8, 45, 'sha256'))->verifyWithWindow((new TOTP($key, 8, 45, 'sha256'))->generate($now), $now, new VerificationWindow(), $cache, $id)->matched,
            'hotp' => static fn(string $key, string $id): bool => (new HOTP($key, 8, 'sha256'))->verifyWithResult((new HOTP($key, 8, 'sha256'))->generate(42), 42, cache: $cache, factorId: $id)->matched,
            'ocra' => static fn(string $key, string $id): bool => OCRA::fromBase32($suite, $key)->verifyWithResult(OCRA::fromBase32($suite, $key)->generate('12345678', counter: 42), '12345678', counter: 42, cache: $cache, factorId: $id)->matched,
        };
        verify($verify($secret, "$type-old") && !$verify($secret, "$type-old"), 'Original generation replay mismatch.');
        verify($verify($next, "$type-new") && !$verify($next, "$type-new"), 'Replacement generation replay mismatch.');
    }
    echo "HOTP/TOTP/OCRA provisioning, parsing, QR, redaction, rotation and shared generation replay boundaries passed\n";
}
