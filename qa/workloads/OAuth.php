<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class OAuth
{
    public function __construct(private Application $app) {}

    public function setup(): void
    {
        $installer = $this->app->make(AuthSchemaInstaller::class);
        $installer->install();
        ensure($installer->installed(), 'OAuth schema is not ready.');
    }

    public function execute(string $id): void
    {
        $oauth = $this->app->make(OAuthManager::class);
        $audience = 'https://api.example.test';
        ensure(count($oauth->jwks()['keys']) === 1, 'Unexpected public signing key set.');
        $client = $oauth->clients()->register(OAuthClientType::Confidential, [OAuthGrantType::ClientCredentials], [], ['service.read'], [$audience]);
        ensure(is_string($client->secret), 'Confidential client secret unavailable.');
        $credentials = new OAuthClientAuthentication(OAuthClientAuthenticationMethod::ClientSecretBasic, $client->client->clientId, $client->secret);
        $token = $oauth->exchange(['grant_type' => 'client_credentials', 'scope' => 'service.read'], $credentials);
        ensure($token->refreshToken === null && $token->scopes === ['service.read'], 'Invalid service token policy.');
        $active = $oauth->introspect($token->accessToken, $credentials);
        ensure($active->active && $active->clientId === $client->client->clientId, 'Service token not active.');
        $oauth->revoke($token->accessToken, $credentials, 'access_token');
        ensure(!$oauth->introspect($token->accessToken, $credentials)->active, 'Revoked token remains active.');

        $account = $this->app->make(AuthServices::class)->accounts()->create('oauth-' . $id . '@example.test')->account;
        ensure($account instanceof Account, 'OAuth account missing.');
        $principal = new Principal($account->id(), accountId: $account->id());
        $redirect = 'https://client.example.test/callback';
        $public = $oauth->clients()->register(OAuthClientType::Public, [OAuthGrantType::AuthorizationCode], [$redirect], ['profile.read'], [$audience]);
        ensure($public->secret === null, 'Public client was issued a secret.');
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $request = $oauth->validateAuthorizationRequest([
            'client_id' => $public->client->clientId, 'redirect_uri' => $redirect, 'response_type' => 'code',
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => 'profile.read', 'audience' => $audience, 'state' => $id,
        ]);
        ensure($oauth->grantConsent($principal, $request)->active(), 'Consent was not persisted.');
        $code = $oauth->approve($request, $principal);
        $params = ['grant_type' => 'authorization_code', 'code' => $code->code, 'redirect_uri' => $redirect, 'code_verifier' => $verifier];
        $publicAuth = new OAuthClientAuthentication(OAuthClientAuthenticationMethod::None, $public->client->clientId);
        $response = $oauth->exchange($params, $publicAuth);
        ensure($response->scopes === ['profile.read'] && $response->accessToken !== '', 'Invalid public token.');
        try {
            $oauth->exchange($params, $publicAuth);
            throw new \RuntimeException('Authorization code replay accepted.');
        } catch (OAuthProtocolException $failure) {
            ensure($failure->error === 'invalid_grant', 'Unexpected OAuth replay error.');
        }
    }

    public function verify(int $expected): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM auth_oauth_clients')) === $expected * 2, 'OAuth client count mismatch.');
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM auth_accounts')) === $expected, 'OAuth account count mismatch.');
    }
}
