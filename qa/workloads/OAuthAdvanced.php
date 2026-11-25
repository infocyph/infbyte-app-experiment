<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Epicrypt\Certificate\KeyPairGenerator;
use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\DpopProof;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\Jwks;
use Infocyph\Epicrypt\Token\Jwt\JwtPolicy;
use Infocyph\Epicrypt\Token\Jwt\OpenIdIdTokenValidator;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptClockAdapter;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthTokenException;
use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenValidator;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class OAuthAdvanced
{
    public function __construct(private Application $app, private string $mode) {}

    public function setup(): void
    {
        $schema = $this->app->make(AuthSchemaInstaller::class);
        $schema->install();
        ensure($schema->installed(), 'OAuth schema not ready.');
        $this->app->make(DBLayerFactory::class)->connection()->statement('CREATE TABLE experiment_oauth_advanced (id VARCHAR(80) PRIMARY KEY)');
    }

    public function execute(string $id): void
    {
        $oauth = $this->app->make(OAuthManager::class);
        $clock = new EpicryptClockAdapter($this->app->make(ClockInterface::class));
        $algorithm = AsymmetricJwtAlgorithm::ES256;
        $issuer = 'https://identity.example.test';
        $audience = $this->mode === 'oidc' ? $issuer . '/oidc/userinfo' : 'https://api.example.test';
        $scopes = match ($this->mode) {
            'oidc' => ['openid', 'profile', 'email'],
            'refresh' => ['profile.read', 'orders.read'],
            'dpop' => ['orders.read'],
        };
        $grants = [OAuthGrantType::AuthorizationCode];
        if ($this->mode === 'refresh') {
            $grants[] = OAuthGrantType::RefreshToken;
        }
        $redirect = 'https://client.example.test/callback';
        $registration = $oauth->clients()->register(OAuthClientType::Public, $grants, [$redirect], $scopes, [$audience]);
        $clientId = $registration->client->clientId;
        $auth = new OAuthClientAuthentication(OAuthClientAuthenticationMethod::None, $clientId);
        $email = $this->mode . '-' . $id . '@example.test';
        $account = $this->app->make(AuthServices::class)->accounts()->create($email)->account;
        ensure($account instanceof Account, 'Account creation failed.');
        $authTime = $clock->now()->getTimestamp() - 15;
        $principal = new Principal($account->id(), accountId: $account->id(), metadata: ['auth_time' => $authTime, 'acr' => 'urn:foundation:loa:2', 'amr' => ['pwd', 'otp']]);
        $verifier = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(16));
        $params = [
            'client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'scope' => implode(' ', $scopes),
            'audience' => $audience, 'state' => $id,
        ];
        if ($this->mode === 'oidc') {
            $params += ['nonce' => $nonce, 'max_age' => '60', 'acr_values' => 'urn:foundation:loa:2'];
        }
        $request = $oauth->validateAuthorizationRequest($params);
        ensure($oauth->grantConsent($principal, $request)->active(), 'Consent missing.');
        $issued = $oauth->approve($request, $principal);
        $exchange = ['grant_type' => 'authorization_code', 'code' => $issued->code, 'redirect_uri' => $redirect, 'code_verifier' => $verifier];

        if ($this->mode === 'dpop') {
            $proofs = new DpopProof($clock);
            $pair = KeyPairGenerator::ec()->generate();
            $jwk = new Jwks()->exportPublicKeyToJwk($pair['public'], 'lab-dpop', $algorithm);
            $proof = $proofs->issue('POST', $issuer . '/oauth/token', $pair['private'], $jwk, $algorithm, jwtId: bin2hex(random_bytes(16)));
            $tokens = $oauth->exchange($exchange, $auth, $proof);
            ensure($tokens->tokenType === 'DPoP', 'Token lost proof binding.');
            $uri = $audience . '/orders/42';
            $resource = $proofs->issue('GET', $uri, $pair['private'], $jwk, $algorithm, accessToken: $tokens->accessToken, jwtId: bin2hex(random_bytes(16)));
            $validator = $this->app->make(OAuthAccessTokenValidator::class);
            $verified = $validator->verifyResource($tokens->accessToken, $audience, 'GET', $uri, $resource);
            ensure($verified->claims->clientId === $clientId && $verified->claims->scopes === $scopes, 'DPoP resource claims mismatch.');
            $other = KeyPairGenerator::ec()->generate();
            $otherJwk = new Jwks()->exportPublicKeyToJwk($other['public'], 'foreign-dpop', $algorithm);
            $wrong = $proofs->issue('GET', $uri, $other['private'], $otherJwk, $algorithm, accessToken: $tokens->accessToken, jwtId: bin2hex(random_bytes(16)));
            foreach ([$resource, $wrong, null] as $invalid) {
                try {
                    $validator->verifyResource($tokens->accessToken, $audience, 'GET', $uri, $invalid);
                    throw new \RuntimeException('Invalid DPoP proof accepted.');
                } catch (OAuthTokenException) {
                    // Proof replay, foreign signing key and missing proof must all fail closed.
                }
            }
        } else {
            $tokens = $oauth->exchange($exchange, $auth);
            if ($this->mode === 'oidc') {
                $token = $tokens->additionalParameters['id_token'] ?? null;
                $key = file_get_contents(getenv('PROJECT') . '/storage/lab-oidc-keys/public.pem');
                ensure(is_string($token) && is_string($key), 'OIDC token or verifier key missing.');
                $verified = AsymmetricJwt::verifier($key, JwtPolicy::openIdIdToken($issuer, $clientId), $algorithm, clock: $clock)->verifyResult($token);
                ensure($verified->valid, 'OIDC signature or claims policy failed.');
                new OpenIdIdTokenValidator($clock)->validate($verified->claims, $algorithm, $clientId, nonce: $nonce, accessToken: $tokens->accessToken, authorizationCode: $issued->code, maximumAuthenticationAge: 60);
                ensure(($verified->claims['sub'] ?? null) === $account->id() && ($verified->claims['auth_time'] ?? null) === $authTime, 'OIDC subject/authentication time mismatch.');
                $info = $oauth->userInfo($tokens->accessToken, $audience, 'GET', $audience);
                ensure(($info['sub'] ?? null) === $account->id() && ($info['email'] ?? null) === $email, 'UserInfo mismatch.');
            } else {
                ensure(is_string($tokens->refreshToken), 'Initial refresh token missing.');
                $rotate = ['grant_type' => 'refresh_token', 'refresh_token' => $tokens->refreshToken];
                $next = $oauth->exchange($rotate + ['scope' => 'profile.read'], $auth);
                ensure(is_string($next->refreshToken) && $next->refreshToken !== $tokens->refreshToken && $next->scopes === ['profile.read'], 'Refresh rotation or scope narrowing failed.');
                $latest = ['grant_type' => 'refresh_token', 'refresh_token' => $next->refreshToken];
                $this->reject($oauth, $latest + ['scope' => 'profile.read orders.read'], $auth, 'invalid_scope');
                $this->reject($oauth, $rotate, $auth, 'invalid_grant');
                $this->reject($oauth, $latest, $auth, 'invalid_grant');
            }
        }
        $this->app->make(DBLayerFactory::class)->connection()->statement('INSERT INTO experiment_oauth_advanced (id) VALUES (?)', [$id]);
    }

    private function reject(OAuthManager $oauth, array $params, OAuthClientAuthentication $auth, string $error): void
    {
        try {
            $oauth->exchange($params, $auth);
            throw new \RuntimeException('Invalid refresh exchange accepted.');
        } catch (OAuthProtocolException $failure) {
            ensure($failure->error === $error, 'Unexpected refresh rejection reason.');
        }
    }

    public function verify(int $expected): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM experiment_oauth_advanced')) === $expected, 'Advanced protocol cycle count mismatch.');
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM auth_accounts')) === $expected, 'Account count mismatch.');
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM auth_oauth_clients')) === $expected, 'Client count mismatch.');
    }
}
