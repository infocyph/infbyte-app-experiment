<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\JwtPolicy;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptClockAdapter;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequest;
use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\DBLayerFactory;
use LogicException;

require __DIR__ . '/bootstrap.php';

function consentRequest(OAuthManager $oauth, string $client, string $scopes, string $audience, string $verifier, ?string $nonce = null): AuthorizationRequest
{
    $parameters = [
        'client_id' => $client, 'redirect_uri' => 'https://client.example.test/callback', 'response_type' => 'code',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256', 'scope' => $scopes, 'audience' => $audience,
    ];
    if ($nonce !== null) {
        $parameters['nonce'] = $nonce;
    }
    return $oauth->validateAuthorizationRequest($parameters);
}

function consentPrincipal(string $id): Principal
{
    return new Principal($id, accountId: $id, metadata: ['auth_time' => time(), 'acr' => 'urn:foundation:loa:2', 'amr' => ['pwd']]);
}

function consentSubject(OAuthManager $oauth, Principal $principal, string $client, string $email, EpicryptClockAdapter $clock): string
{
    $audience = 'https://identity.example.test/oidc/userinfo';
    $verifier = bin2hex(random_bytes(32));
    $nonce = bin2hex(random_bytes(16));
    $request = consentRequest($oauth, $client, 'openid email', $audience, $verifier, $nonce);
    ensure($oauth->hasConsent($principal, $request), 'OIDC consent missing for subject test.');
    $issued = $oauth->approve($request, $principal);
    $tokens = $oauth->exchange([
        'grant_type' => 'authorization_code', 'code' => $issued->code, 'redirect_uri' => $request->redirectUri, 'code_verifier' => $verifier,
    ], new OAuthClientAuthentication(OAuthClientAuthenticationMethod::None, $client));
    $token = $tokens->additionalParameters['id_token'] ?? null;
    $key = file_get_contents(getenv('PROJECT') . '/storage/lab-oidc-keys/public.pem');
    ensure(is_string($token) && is_string($key), 'OIDC response missing.');
    $verified = AsymmetricJwt::verifier($key, JwtPolicy::openIdIdToken('https://identity.example.test', $client), AsymmetricJwtAlgorithm::ES256, clock: $clock)->verifyResult($token);
    ensure($verified->valid && ($verified->claims['nonce'] ?? null) === $nonce, 'OIDC signature/nonce mismatch.');
    $subject = $verified->claims['sub'] ?? null;
    $info = $oauth->userInfo($tokens->accessToken, $audience, 'GET', $audience);
    ensure(is_string($subject) && $subject === $principal->accountId() && $info['sub'] === $subject && $info['email'] === $email, 'Public subject or UserInfo mismatch.');
    return $subject;
}

$app = application();
$phase = $argv[1] ?? '';
$app->execution()->run(static function () use ($app, $phase): void {
    $path = (string) getenv('LAB_CONSENT_STATE');
    $audience = 'https://identity.example.test/oidc/userinfo';
    $api = 'https://api.example.test';
    if ($phase === 'seed') {
        $schema = $app->make(AuthSchemaInstaller::class);
        $schema->install();
        ensure($schema->installed(), 'Consent schema unavailable.');
    }
    $oauth = $app->make(OAuthManager::class);
    if ($phase === 'seed') {
        $accounts = $app->make(AuthServices::class)->accounts();
        $owner = $accounts->create('consent-owner@example.test')->account;
        $other = $accounts->create('consent-other@example.test')->account;
        ensure($owner !== null && $other !== null, 'Consent accounts unavailable.');
        $register = static fn() => $oauth->clients()->register(OAuthClientType::Public, [OAuthGrantType::AuthorizationCode], ['https://client.example.test/callback'], ['openid', 'email', 'profile.read'], [$audience, $api])->client->clientId;
        $state = ['owner' => $owner->id(), 'other' => $other->id(), 'client' => $register(), 'other_client' => $register()];
    } else {
        $state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
    $owner = consentPrincipal($state['owner']);
    $other = consentPrincipal($state['other']);
    $verifier = bin2hex(random_bytes(32));
    $full = consentRequest($oauth, $state['client'], 'openid email', $audience, $verifier);
    $reordered = consentRequest($oauth, $state['client'], 'email openid', $audience, $verifier);
    $narrow = consentRequest($oauth, $state['client'], 'openid', $audience, $verifier);
    $apiRequest = consentRequest($oauth, $state['client'], 'profile.read', $api, $verifier);
    $otherClient = consentRequest($oauth, $state['other_client'], 'openid email', $audience, $verifier);
    $sameScopeOtherAudience = new AuthorizationRequest($full->client, $full->redirectUri, $full->codeChallenge, $full->scopes, [$api]);
    $db = $app->make(DBLayerFactory::class)->connection();
    $count = static fn(): int => (int) $db->scalar('SELECT COUNT(*) FROM auth_oauth_consents');
    $save = static function () use (&$state, $path): void {
        ensure(file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'Private consent state unavailable.');
    };
    if ($phase === 'seed') {
        ensure(!$oauth->hasConsent($owner, $full), 'Initial consent existed.');
        $granted = $oauth->grantConsent($owner, $full);
        $state['grant'] = $granted->id;
        $state['fingerprint'] = $granted->scopeFingerprint;
        ensure($granted->active() && $oauth->grantConsent($owner, $reordered)->id === $granted->id && $count() === 1, 'Canonical consent was not idempotent.');
        ensure(!$oauth->hasConsent($other, $full) && !$oauth->hasConsent($owner, $otherClient) && !$oauth->hasConsent($owner, $narrow) && !$oauth->hasConsent($owner, $sameScopeOtherAudience), 'Consent crossed an identity boundary.');
        $oauth->grantConsent($owner, $apiRequest);
        $oauth->grantConsent($owner, $otherClient);
        $oauth->grantConsent($other, $full);
        $before = $count();
        try {
            $oauth->grantConsent(new Principal('non-account'), $full);
            throw new \RuntimeException('Non-account consent accepted.');
        } catch (LogicException) {
            ensure($count() === $before, 'Rejected principal persisted consent.');
        }
        ensure($count() === 4, 'Consent row count mismatch.');
        $save();
    } elseif ($phase === 'revoke') {
        ensure($oauth->hasConsent($owner, $reordered) && $oauth->hasConsent($owner, $apiRequest) && $oauth->hasConsent($owner, $otherClient) && $oauth->hasConsent($other, $full), 'Consent did not survive process replacement.');
        ensure($oauth->revokeConsent($owner, $state['client']) === 2 && $oauth->revokeConsent($owner, $state['client']) === 0, 'Consent revoke count or idempotency mismatch.');
        ensure(!$oauth->hasConsent($owner, $full) && !$oauth->hasConsent($owner, $apiRequest) && $oauth->hasConsent($owner, $otherClient) && $oauth->hasConsent($other, $full) && $count() === 4, 'Revocation crossed account/client or erased durable history.');
    } elseif ($phase === 'regrant') {
        ensure(!$oauth->hasConsent($owner, $full) && !$oauth->hasConsent($owner, $apiRequest), 'Revoked grants returned after restart.');
        $renewed = $oauth->grantConsent($owner, $reordered);
        ensure($renewed->id === $state['grant'] && $renewed->scopeFingerprint === $state['fingerprint'] && $renewed->active() && $count() === 4, 'Regrant failed to reconcile durable identity.');
    } else {
        ensure($phase === 'verify', 'Unknown consent phase.');
        ensure($oauth->hasConsent($owner, $full) && !$oauth->hasConsent($owner, $apiRequest) && !$oauth->hasConsent($owner, $narrow) && $oauth->hasConsent($other, $full) && $oauth->hasConsent($owner, $otherClient), 'Final consent isolation failed.');
        $clock = new EpicryptClockAdapter($app->make(ClockInterface::class));
        $first = consentSubject($oauth, $owner, $state['client'], 'consent-owner@example.test', $clock);
        $second = consentSubject($oauth, $owner, $state['other_client'], 'consent-owner@example.test', $clock);
        $third = consentSubject($oauth, $other, $state['client'], 'consent-other@example.test', $clock);
        ensure($first === $second && $first !== $third && $count() === 4, 'Public subject continuity or consent identity changed.');
    }
    echo "Consent lifecycle phase passed: $phase\n";
});
