<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Foundation;

require getenv('PROJECT') . '/vendor/autoload.php';

/** @throws \RuntimeException */
function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

/** @throws \Throwable */
function application(): Application
{
    $root = getenv('PROJECT');
    ensure(is_string($root) && is_dir($root), 'Missing isolated application.');
    $driver = getenv('DB_BACKEND');
    ensure(in_array($driver, ['mysql', 'pgsql'], true), 'Unexpected database backend.');

    return Foundation::worker([
        'app' => ['base_path' => $root, 'load_env' => false, 'env' => 'local', 'capabilities' => [
            'auth', 'cache', 'database', 'filesystem', 'validation', 'security', 'notifications',
            'communication', 'messaging', 'otp', 'passkeys', 'session', 'runwire',
        ]],
        '_config_cache' => false,
        'database' => [
            'default' => 'main',
            'connections' => ['main' => [
                'driver' => $driver,
                'host' => '127.0.0.1',
                'port' => $driver === 'mysql' ? 3306 : 5432,
                'database' => 'experiment',
                'username' => 'experiment',
                'password' => 'experiment',
            ]],
            'pool' => ['enabled' => false],
        ],
        'cache' => [
            'default' => 'shared',
            'default_counter' => 'shared',
            'counters' => ['shared' => ['driver' => 'redis', 'connection' => 'redis', 'namespace' => 'experiment-counter']],
            'connections' => ['redis' => ['driver' => 'redis', 'dsn' => 'redis://127.0.0.1:6379']],
            'stores' => ['shared' => [
                'driver' => 'redis', 'connection' => 'redis', 'namespace' => 'experiment-stress', 'fail_open' => false,
                'security' => ['integrity_key' => getenv('AUTH_TOKEN_SECRET')],
            ]],
        ],
        'auth' => [
            'drivers' => ['storage' => 'database', 'cache' => 'cache', 'passwords' => 'security', 'tokens' => 'simple', 'notifications' => 'collect',
                'mfa' => getenv('SCENARIO') === 'security' ? 'otp' : 'simple',
                'passkey' => getenv('SCENARIO') === 'security' ? 'webauthn' : 'memory'],
            'token_secret_environment' => 'AUTH_TOKEN_SECRET',
            'otp' => ['replay' => ['store' => 'shared'], 'totp' => ['algorithm' => 'sha256', 'digits' => 6, 'period' => 30, 'secret_bytes' => 32, 'window' => 1],
                'secret_protection' => ['keys' => [['id' => 'experiment', 'environment' => 'AUTH_OTP_PROTECTION_KEY', 'status' => 'active']]]],
            'passkey' => ['state' => ['store' => 'shared']],
            'webauthn' => ['rp_id' => 'experiment.test', 'origin' => 'https://experiment.test', 'challenge_ttl' => 300],
        ],
        'validation' => [
            'database_connection' => 'main',
            'schemas' => ['orders.create' => [
                'sku' => 'required|integer|exists:experiment_stock,id',
                'quantity' => 'required|integer|min:1|max:5',
            ]],
        ],
        'messaging' => [
            'durable' => ['enabled' => true, 'connection' => 'main', 'failure_store' => 'database', 'tables' => []],
            'serialization' => ['message_codecs' => [ReceiptCodec::class]],
            'default_route' => ['transport' => 'database', 'queue' => 'receipts'],
            'routes' => [Receipt::class => ['transport' => 'database', 'queue' => 'receipts']],
            'handlers' => [Receipt::class => ReceiptHandler::class],
            'consumer' => ['transport' => 'database'],
            'retry' => ['maximum_attempts' => 2, 'initial_delay_seconds' => 0.0, 'multiplier' => 1.0, 'maximum_delay_seconds' => 0.0, 'jitter_ratio' => 0.0],
        ],
        'notifications' => ['email' => [
            'default_sender' => 'mailpit',
            'senders' => ['mailpit' => ['transport' => 'mailpit']],
            'transports' => ['mailpit' => [
                'driver' => 'smtp', 'host' => '127.0.0.1', 'port' => 1025,
                'security' => 'none', 'timeoutSeconds' => 5,
            ]],
        ]],
    ])->boot();
}

/** @throws \RuntimeException */
function countValue(mixed $value): int
{
    if (!is_numeric($value)) {
        throw new \RuntimeException('Expected numeric database aggregate.');
    }

    return (int) $value;
}
