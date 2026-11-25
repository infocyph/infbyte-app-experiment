<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;

require __DIR__ . '/bootstrap.php';

$app = application();
$schema = $app->make(AuthSchemaInstaller::class);
$schema->install();
ensure($schema->installed(), 'OAuth schema unavailable.');
$oauth = $app->make(OAuthManager::class);
$registration = $oauth->clients()->register(OAuthClientType::Confidential, [OAuthGrantType::ClientCredentials], [], ['service.read'], ['https://api.example.test']);
ensure(is_string($registration->secret), 'Missing client secret.');
$path = getenv('RUNNER_TEMP') . '/oauth-http-fixture.json';
ensure(file_put_contents($path, json_encode(['id' => $registration->client->clientId, 'secret' => $registration->secret], JSON_THROW_ON_ERROR), LOCK_EX) !== false && chmod($path, 0600), 'Private fixture write failed.');
