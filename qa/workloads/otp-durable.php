<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Mfa\RecoveryCodeServiceInterface;

require __DIR__ . '/bootstrap.php';

$app = application();
$mode = $argv[1] ?? '';
$path = getenv('LAB_OTP_FIXTURE');
ensure(is_string($path) && $path !== '', 'Private recovery fixture path missing.');
$service = static fn(): RecoveryCodeServiceInterface => $app->make(RecoveryCodeServiceInterface::class);
if ($mode === 'setup') {
    $app->execution()->run(static function () use ($app, $path, $service): void {
        new Accounts($app)->setup();
        $owner = $app->make(AuthServices::class)->accounts()->create('durable-recovery@example.test')->account;
        $foreign = $app->make(AuthServices::class)->accounts()->create('other-recovery@example.test')->account;
        ensure($owner instanceof Account && $foreign instanceof Account, 'Missing accounts.');
        $old = $service()->generate($owner->id(), 8);
        $new = $service()->generate($owner->id(), 8);
        ensure(count($old) === 8 && count($new) === 8, 'Recovery generation mismatch.');
        ensure(!$service()->verify($owner->id(), $old[0])->verified, 'Old generation remained valid.');
        ensure(!$service()->verify($foreign->id(), $new[0])->verified, 'Cross-account proof accepted.');
        ensure(file_put_contents($path, json_encode(['owner' => $owner->id(), 'codes' => $new], JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'Private fixture write failed.');
    });
} else {
    $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if ($mode === 'race') {
        $winner = $app->execution()->run(static fn(): bool => $service()->verify($fixture['owner'], $fixture['codes'][0])->verified);
        echo (int) $winner, PHP_EOL;
    } elseif ($mode === 'verify') {
        $app->execution()->run(static function () use ($service, $fixture): void {
            ensure(!$service()->verify($fixture['owner'], $fixture['codes'][0])->verified, 'Consumed proof accepted after process replacement.');
            foreach (array_slice($fixture['codes'], 1) as $code) {
                ensure($service()->verify($fixture['owner'], $code)->verified, 'Unspent recovery proof missing.');
                ensure(!$service()->verify($fixture['owner'], $code)->verified, 'Recovery replay accepted.');
            }
        });
    } else {
        throw new \InvalidArgumentException('Unknown recovery mode.');
    }
}
