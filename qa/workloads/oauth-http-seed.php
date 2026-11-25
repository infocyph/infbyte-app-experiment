<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Http\AuthActions;
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
$redirect = 'https://client.example.test/callback';
$public = $oauth->clients()->register(OAuthClientType::Public, [OAuthGrantType::AuthorizationCode], [$redirect], ['profile.read'], ['https://api.example.test']);
ensure($public->secret === null, 'Public client unexpectedly has a secret.');
$oidc = $oauth->clients()->register(OAuthClientType::Public, [OAuthGrantType::AuthorizationCode], [$redirect], ['openid', 'email', 'profile'], ['https://identity.example.test/oidc/userinfo']);
ensure($oidc->secret === null, 'OIDC public client unexpectedly has a secret.');
$auth = $app->make(AuthServices::class);
$password = bin2hex(random_bytes(32));
$email = 'oauth-browser@example.test';
$account = $auth->accounts()->create($email, $auth->passwordHasher()->hash($password))->account;
ensure($account !== null, 'Browser subject fixture missing.');
$login = $app->make(AuthActions::class)->login(['identifier' => $email, 'password' => $password]);
ensure($login->authenticated() && $login->session !== null, 'Browser subject authentication failed.');
$path = getenv('RUNNER_TEMP') . '/oauth-http-fixture.json';
ensure(file_put_contents($path, json_encode(['id' => $registration->client->clientId, 'secret' => $registration->secret, 'public_id' => $public->client->clientId, 'oidc_id' => $oidc->client->clientId, 'redirect' => $redirect, 'session' => $login->session->id], JSON_THROW_ON_ERROR), LOCK_EX) !== false && chmod($path, 0600), 'Private fixture write failed.');
