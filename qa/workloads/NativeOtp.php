<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\CacheLayer\Cache\AuthenticationStateCacheInterface;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\OTP\AOTP;
use Infocyph\OTP\GenericOtp;
use Infocyph\OTP\GridOTP;
use Infocyph\OTP\HOTP;
use Infocyph\OTP\MobileOTP;
use Infocyph\OTP\OCRA;
use Infocyph\OTP\TOTP;
use Infocyph\OTP\ValueObjects\VerificationWindow;

final readonly class NativeOtp
{
    public function __construct(private Application $app) {}

    /** @throws \Throwable */
    public function setup(): void
    {
        $this->app->make(DBLayerFactory::class)->connection()->statement('CREATE TABLE experiment_otp (id VARCHAR(80) PRIMARY KEY, protocols INTEGER NOT NULL)');
    }

    /** @throws \Throwable */
    public function execute(string $id): void
    {
        $cache = $this->app->make(CacheManager::class)->store('shared');
        ensure($cache instanceof AuthenticationStateCacheInterface, 'Authoritative authentication state unavailable.');
        $key = hex2bin((string) getenv('LAB_GENERIC_OTP_KEY'));
        ensure(is_string($key) && strlen($key) === 32, 'Missing independent generic OTP key.');
        $generic = new GenericOtp($cache, $key);
        $code = $generic->generate('checkout:' . $id);
        ensure($generic->verify('checkout:' . $id, $code) && !$generic->verify('checkout:' . $id, $code), 'Generic OTP single-use failed.');

        $now = time();
        $window = new VerificationWindow(0, 0);
        $totp = new TOTP(TOTP::generateSecret(), 6, 30, 'sha256');
        $code = $totp->generate($now);
        ensure($totp->verifyWithWindow($code, $now, $window, cache: $cache, factorId: $id . ':totp')->matched, 'TOTP rejected.');
        ensure(!$totp->verifyWithWindow($code, $now, $window, cache: $cache, factorId: $id . ':totp')->matched, 'TOTP replay accepted.');

        $hotp = new HOTP(HOTP::generateSecret(), 6, 'sha256');
        $code = $hotp->generate(12);
        $result = $hotp->verifyWithResult($code, counter: 10, lookAhead: 4, cache: $cache, factorId: $id . ':hotp');
        ensure($result->matched && $result->nextCounter === 13, 'HOTP resynchronization failed.');
        ensure(!$hotp->verifyWithResult($code, counter: 10, lookAhead: 4, cache: $cache, factorId: $id . ':hotp')->matched, 'HOTP replay accepted.');

        $ocra = new OCRA('OCRA-1:HOTP-SHA256-8:C-QN08', random_bytes(32));
        $code = $ocra->generate('12345678', counter: 4);
        $result = $ocra->verifyWithResult($code, '12345678', counter: 4, cache: $cache, factorId: $id . ':ocra');
        ensure($result->matched && $result->nextCounter === 5, 'OCRA challenge failed.');
        ensure(!$ocra->verifyWithResult($code, '12345678', counter: 4, cache: $cache, factorId: $id . ':ocra')->matched, 'OCRA replay accepted.');

        $keys = AOTP::generateKeyPair();
        $aotp = new AOTP($keys->publicKey, 'experiment.test');
        $challenge = $aotp->issue(cache: $cache, factorId: $id . ':aotp', context: 'checkout:' . $id, ttlSeconds: 120);
        $response = AOTP::respond($keys->privateKey, $challenge, 'experiment.test', 'checkout:' . $id);
        ensure($aotp->verifyWithResult($cache, $id . ':aotp', $challenge, $response)->matched, 'AOTP signature rejected.');
        ensure(!$aotp->verifyWithResult($cache, $id . ':aotp', $challenge, $response)->matched, 'AOTP replay accepted.');

        $secret = GridOTP::generateSecret();
        $grid = new GridOTP(cache: $cache, secret: $secret, enforceDiversity: true);
        $challenge = $grid->issue($id . ':grid');
        $response = GridOTP::respond($challenge, $secret);
        ensure($grid->verifyWithResult($id . ':grid', $challenge, $response)->matched, 'GridOTP response rejected.');
        ensure(!$grid->verifyWithResult($id . ':grid', $challenge, $response)->matched, 'GridOTP replay accepted.');

        $mobile = new MobileOTP(MobileOTP::generateSecret(), '4826');
        $code = $mobile->generate($now);
        ensure($mobile->verifyWithWindow($code, $now, $window, cache: $cache, factorId: $id . ':mobile')->matched, 'Legacy MobileOTP rejected.');
        ensure(!$mobile->verifyWithWindow($code, $now, $window, cache: $cache, factorId: $id . ':mobile')->matched, 'Legacy MobileOTP replay accepted.');

        $this->app->make(DBLayerFactory::class)->connection()->statement('INSERT INTO experiment_otp (id, protocols) VALUES (?, ?)', [$id, 7]);
    }

    /** @throws \Throwable */
    public function verify(int $expected): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM experiment_otp')) === $expected, 'OTP workflow count mismatch.');
        ensure(countValue($db->scalar('SELECT SUM(protocols) FROM experiment_otp')) === $expected * 7, 'OTP protocol coverage mismatch.');
    }
}
