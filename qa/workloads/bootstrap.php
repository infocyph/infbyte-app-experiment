<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Foundation;

require getenv('PROJECT') . '/vendor/autoload.php';

/** @throws \Throwable */
function configuration(): array
{
    $root = getenv('PROJECT');
    ensure(is_string($root) && is_dir($root), 'Missing isolated application.');
    $driver = getenv('DB_BACKEND');
    ensure(in_array($driver, ['mysql', 'pgsql'], true), 'Unexpected database backend.');

    $config = [
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
                'mfa' => in_array(getenv('SCENARIO'), ['security', 'ecosystem-otp-durable'], true) ? 'otp' : 'simple',
                'passkey' => in_array(getenv('SCENARIO'), ['security', 'passkey'], true) ? 'webauthn' : 'memory'],
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
            'routes' => [Receipt::class => ['transport' => 'database', 'queue' => 'receipts', 'delay_seconds' => 0.0]],
            'handlers' => [Receipt::class => ReceiptHandler::class],
            'consumer' => ['transport' => 'database'],
            'retry' => ['maximum_attempts' => 2, 'initial_delay_seconds' => 0.0, 'multiplier' => 1.0, 'maximum_delay_seconds' => 0.0, 'jitter_ratio' => 0.0],
        ],
        'communication' => [
            'http' => ['default_client' => 'lab', 'clients' => ['lab' => [
                'timeoutSeconds' => 5, 'connectTimeoutSeconds' => 2,
            ]]],
            'webhooks' => [
                'default_inbound' => 'lab',
                'inbound' => ['lab' => ['secret' => getenv('AUTH_TOKEN_SECRET'), 'max_age_seconds' => 300, 'replay' => ['enabled' => true, 'store' => 'shared', 'ttl_seconds' => 600]]],
            ],
        ],
        'notifications' => ['email' => [
            'default_sender' => 'mailpit',
            'senders' => ['mailpit' => ['transport' => 'mailpit']],
            'transports' => ['mailpit' => [
                'driver' => 'smtp', 'host' => '127.0.0.1', 'port' => 1025,
                'security' => 'none', 'timeoutSeconds' => 5,
            ]],
        ]],
    ];
    if (in_array(getenv('SCENARIO'), ['http', 'browser', 'ecosystem-connected', 'ecosystem-endurance', 'ecosystem-tenants'], true)) {
        $matcher = getenv('LAB_MATCHER') ?: 'fused';
        ensure(in_array($matcher, ['fused', 'sharded'], true), 'Unsupported laboratory matcher.');
        $config['router'] = ['matcher' => $matcher];
    }

    if (in_array(getenv('SCENARIO'), ['http', 'ecosystem-tenants'], true)) {
        $config['app']['env'] = 'production';
        $config['app']['debug'] = false;
        $config['app']['capabilities'] = ['auth', 'cache', 'database', 'filesystem', 'validation', 'security', 'otp', 'passkeys', 'notifications', 'communication', 'runwire'];
        $config['auth']['drivers']['tokens'] = 'security';
        $config['auth']['drivers']['mfa'] = 'otp';
        $config['auth']['drivers']['passkey'] = 'webauthn';
        $config['auth']['drivers']['notifications'] = 'talkingbytes';
        $config['auth']['http']['resolver_order'] = ['bearer'];
        $config['security']['jwt'] = ['algorithm' => 'HS256', 'issuer' => 'https://experiment.test', 'audience' => 'experiment-api'];
        $config['cache']['serialization'] = ['allow_object_payloads' => false, 'allow_closure_payloads' => false];
        $config['notifications']['auth'] = ['sender' => 'mailpit', 'from' => 'security@example.test'];
        $config['notifications']['email']['default_from'] = 'security@example.test';
    }

    if (getenv('SCENARIO') === 'browser') {
        $config['app']['env'] = 'production';
        $config['app']['debug'] = false;
        $config['app']['capabilities'] = ['cache', 'session', 'filesystem', 'runwire'];
        $config['cache']['serialization'] = ['allow_object_payloads' => false, 'allow_closure_payloads' => false];
        $config['session'] = [
            'driver' => 'cache',
            'stores' => ['cache' => ['store' => 'shared']],
            'cookie' => ['name' => 'lab_session', 'secure' => true, 'http_only' => true, 'same_site' => 'Strict', 'path' => '/'],
            'lock' => ['enabled' => true, 'store' => 'shared', 'wait' => 10.0, 'lease' => 30.0],
            'csrf' => ['check_origin' => true, 'origin' => 'https://experiment.test'],
        ];
    }

    if (getenv('SCENARIO') === 'ecosystem-connected') {
        $config['app']['env'] = 'production';
        $config['app']['debug'] = false;
        $config['app']['capabilities'] = ['cache', 'database', 'filesystem', 'validation', 'security', 'notifications', 'communication', 'messaging', 'runwire'];
        $config['messaging']['handlers'] = [Receipt::class => ConnectedReceipt::class];
        $config['cache']['serialization'] = ['allow_object_payloads' => false, 'allow_closure_payloads' => false];
    }

    if (getenv('SCENARIO') === 'ecosystem-endurance') {
        $config['app']['env'] = 'production';
        $config['app']['debug'] = false;
        $config['app']['capabilities'] = ['cache', 'database', 'validation', 'runwire'];
        $config['cache']['serialization'] = ['allow_object_payloads' => false, 'allow_closure_payloads' => false];
    }

    if (getenv('SCENARIO') === 'operations') {
        $config['app']['env'] = 'production';
        $config['app']['debug'] = false;
        $config['app']['capabilities'] = ['cache', 'database', 'filesystem', 'validation', 'runwire'];
    }

    if (in_array(getenv('SCENARIO'), ['oauth', 'refresh', 'oidc', 'dpop'], true)) {
        $keyRoot = $root . '/storage/lab-oauth-keys';
        $config['auth']['oauth'] = [
            'enabled' => true, 'issuer' => 'https://identity.example.test',
            'resource_audiences' => ['https://api.example.test'], 'scope_permissions' => [],
            'grants' => ['authorization_code', 'client_credentials', 'refresh_token'],
            'signing' => [
                'algorithm' => 'ES256', 'active_key_id' => 'lab-key',
                'private_key' => $keyRoot . '/private.pem',
                'public_keys' => [['id' => 'lab-key', 'path' => $keyRoot . '/public.pem', 'status' => 'active']],
            ],
        ];
    }

    if (getenv('SCENARIO') === 'oidc') {
        $keyRoot = $root . '/storage/lab-oidc-keys';
        $config['auth']['oauth']['resource_audiences'][] = 'https://identity.example.test/oidc/userinfo';
        $config['auth']['oauth']['oidc'] = [
            'enabled' => true, 'id_token_lifetime_seconds' => 300,
            'userinfo_route' => '/oidc/userinfo', 'userinfo_audience' => 'https://identity.example.test/oidc/userinfo',
            'scopes_supported' => ['openid', 'profile', 'email'], 'claims_supported' => ['sub', 'email', 'auth_time', 'acr', 'amr'],
            'subject_types' => ['public'],
            'signing' => [
                'algorithm' => 'ES256', 'active_key_id' => 'oidc-key',
                'private_key' => $keyRoot . '/private.pem',
                'public_keys' => [['id' => 'oidc-key', 'path' => $keyRoot . '/public.pem', 'status' => 'active']],
            ],
        ];
    }
    if (getenv('SCENARIO') === 'pat') {
        $keyRoot = $root . '/storage/lab-pat-keys';
        $config['auth']['personal_access_tokens'] = [
            'enabled' => true, 'issuer' => 'https://identity.example.test', 'audience' => 'https://api.example.test',
            'default_lifetime_seconds' => 3600, 'maximum_lifetime_seconds' => 86400,
            'wildcard_policy' => 'disabled', 'last_used_write_interval_seconds' => 60,
            'signing' => [
                'algorithm' => 'ES256', 'active_key_id' => 'pat-key',
                'private_key' => $keyRoot . '/private.pem',
                'public_keys' => [['id' => 'pat-key', 'path' => $keyRoot . '/public.pem', 'status' => 'active']],
            ],
        ];
    }

    return $config;
}


/** @throws \Throwable */
function application(): Application
{
    return Foundation::worker(configuration())->boot();
}
