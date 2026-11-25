<?php

declare(strict_types=1);

namespace Experiment;

require getenv('PROJECT') . '/vendor/autoload.php';

function oauthHttp(string $path, int $status, ?string $body = null, array $headers = [], bool $raw = false): array
{
    $curl = curl_init(getenv('LAB_HTTP_URL') . $path);
    ensure($curl instanceof \CurlHandle, 'Cannot initialize OAuth HTTP client.');
    $received = [];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$received): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $received[strtolower(trim($parts[0]))][] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
    }
    $response = curl_exec($curl);
    ensure(is_string($response) && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === $status, 'OAuth endpoint status mismatch: ' . strtok($path, '?'));
    return [$raw ? $response : ($response === '' ? [] : json_decode($response, true, flags: JSON_THROW_ON_ERROR)), $received];
}

function oauthNoStore(array $headers): void
{
    ensure(in_array('no-store', $headers['cache-control'] ?? [], true) && in_array('no-cache', $headers['pragma'] ?? [], true), 'OAuth token response cache policy unsafe.');
}

$path = getenv('RUNNER_TEMP') . '/oauth-http-fixture.json';
$fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$basic = 'Authorization: Basic ' . base64_encode(urlencode($fixture['id']) . ':' . urlencode($fixture['secret']));
$form = ['Content-Type: application/x-www-form-urlencoded', $basic];
$grant = 'grant_type=client_credentials&scope=service.read';
if (($argv[1] ?? '') === 'seed') {
    [$metadata] = oauthHttp('/.well-known/oauth-authorization-server', 200);
    ensure($metadata['issuer'] === 'https://identity.example.test' && $metadata['token_endpoint'] === 'https://identity.example.test/oauth/token', 'Discovery metadata mismatch.');
    [$oidc] = oauthHttp('/.well-known/openid-configuration', 200);
    ensure($oidc['userinfo_endpoint'] === 'https://identity.example.test/oidc/userinfo', 'OIDC discovery mismatch.');
    [$jwks] = oauthHttp('/.well-known/jwks.json', 200);
    ensure(count($jwks['keys']) === 1 && $jwks['keys'][0]['kid'] === 'lab-key' && !isset($jwks['keys'][0]['d']), 'JWKS leaked private key or wrong identity.');
    foreach ([
        ['/oauth/token', $grant . '&grant_type=client_credentials', $form],
        ['/oauth/token?scope=service.read', $grant, $form],
        ['/oauth/token', $grant . '&client_id=' . rawurlencode($fixture['id']), $form],
        ['/oauth/token', $grant . '&extra=%ZZ', $form],
        ['/oauth/token', '{}', ['Content-Type: application/json', $basic]],
    ] as [$endpoint, $body, $headers]) {
        [$error, $responseHeaders] = oauthHttp($endpoint, 400, $body, $headers);
        ensure($error['error'] === 'invalid_request', 'Protocol input failure mismatch.');
        oauthNoStore($responseHeaders);
    }
    [$invalid, $headers] = oauthHttp('/oauth/token', 401, $grant, ['Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . base64_encode(urlencode($fixture['id']) . ':wrong')]);
    ensure($invalid['error'] === 'invalid_client' && isset($headers['www-authenticate']), 'Invalid client protocol mismatch.');
    [$token, $headers] = oauthHttp('/oauth/token', 200, $grant, $form);
    oauthNoStore($headers);
    ensure(is_string($token['access_token']) && !isset($token['refresh_token']), 'Client credentials token shape invalid.');
    $fixture['token'] = $token['access_token'];
    $fixture['authorization'] = oauthBrowserConsent($fixture, $metadata);
    ensure(file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'Private token fixture write failed.');
    [$active, $headers] = oauthHttp('/oauth/introspect', 200, http_build_query(['token' => $fixture['token']]), $form);
    oauthNoStore($headers);
    ensure($active['active'] === true && $active['client_id'] === $fixture['id'], 'Introspection did not recognize issued token.');
    echo "Foundation-owned OAuth/OIDC discovery, JWKS, malformed transport, client authentication and issuance passed\n";
} else {
    $tokenForm = http_build_query(['token' => $fixture['token']]);
    [$active] = oauthHttp('/oauth/introspect', 200, $tokenForm, $form);
    ensure($active['active'] === true, 'Host replacement lost authoritative OAuth state.');
    [, $headers] = oauthHttp('/oauth/revoke', 200, $tokenForm . '&token_type_hint=access_token', $form);
    oauthNoStore($headers);
    [$inactive] = oauthHttp('/oauth/introspect', 200, $tokenForm, $form);
    ensure($inactive['active'] === false, 'Revoked token remained active.');
    oauthHttp('/oauth/revoke', 200, $tokenForm, $form);
    [$next] = oauthHttp('/oauth/token', 200, $grant, $form);
    ensure($next['access_token'] !== $fixture['token'], 'New issuance reused revoked token.');
    oauthBrowserRedeem($fixture);
    echo "Host replacement, authoritative introspection, idempotent revocation and fresh issuance passed\n";
}


function oauthBrowserSession(array $fixture, string $path): array
{
    [$html, $headers] = oauthHttp($path, 200, headers: ['Cookie: foundation_session=' . $fixture['session']], raw: true);
    oauthNoStore($headers);
    ensure(str_contains($html, 'profile.read') && str_contains($html, 'https://api.example.test'), 'Consent page omitted requested scope or resource.');
    ensure(str_contains($headers['content-security-policy'][0] ?? '', "frame-ancestors 'none'")
        && ($headers['x-frame-options'][0] ?? '') === 'DENY'
        && ($headers['referrer-policy'][0] ?? '') === 'no-referrer', 'Consent document security headers missing.');
    ensure(preg_match('/name="_token" value="([a-f0-9]{64})"/', $html, $match) === 1, 'Consent form token missing.');
    foreach ($headers['set-cookie'] ?? [] as $value) {
        if (str_starts_with($value, 'lab_session=')) {
            ensure(preg_match('/(?:^|;\s*)Secure(?:;|$)/i', $value) === 1
                && preg_match('/(?:^|;\s*)HttpOnly(?:;|$)/i', $value) === 1
                && preg_match('/(?:^|;\s*)SameSite=Strict(?:;|$)/i', $value) === 1, 'Unsafe consent session cookie attributes.');
            return ['cookie' => 'foundation_session=' . $fixture['session'] . '; ' . explode(';', $value, 2)[0], 'csrf' => $match[1]];
        }
    }
    throw new \RuntimeException('Consent browser session cookie missing.');
}

function oauthConsentRedirect(array $headers, string $redirect, string $state): array
{
    $location = $headers['location'][0] ?? null;
    ensure(is_string($location) && str_starts_with($location, $redirect . '?'), 'Consent did not redirect to registered URI.');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $parameters);
    ensure(($parameters['state'] ?? null) === $state && ($parameters['iss'] ?? null) === 'https://identity.example.test', 'Consent redirect lost state or issuer binding.');
    return $parameters;
}

function oauthBrowserConsent(array $fixture, array $metadata): array
{
    $route = parse_url($metadata['authorization_endpoint'], PHP_URL_PATH);
    ensure(is_string($route) && str_starts_with($route, '/'), 'Authorization route missing.');
    $verifier = bin2hex(random_bytes(32));
    $parameters = [
        'response_type' => 'code', 'client_id' => $fixture['public_id'], 'redirect_uri' => $fixture['redirect'],
        'scope' => 'profile.read', 'audience' => 'https://api.example.test',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256', 'state' => 'browser-deny',
    ];
    $path = $route . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    oauthHttp($path, 401, raw: true);
    $browser = oauthBrowserSession($fixture, $path);
    $other = oauthBrowserSession($fixture, $path);
    ensure($browser['cookie'] !== $other['cookie'] && $browser['csrf'] !== $other['csrf'], 'Independent browser sessions shared CSRF state.');
    $headers = ['Content-Type: application/x-www-form-urlencoded', 'Origin: https://identity.example.test', 'Cookie: ' . $browser['cookie']];
    oauthHttp($path, 419, 'decision=approve', $headers, raw: true);
    $form = http_build_query(['decision' => 'approve', '_token' => $browser['csrf']]);
    oauthHttp($path, 419, $form, ['Content-Type: application/x-www-form-urlencoded', 'Origin: https://foreign.example.test', 'Cookie: ' . $browser['cookie']], raw: true);
    oauthHttp($path, 419, $form, ['Content-Type: application/x-www-form-urlencoded', 'Origin: https://identity.example.test', 'Cookie: ' . $other['cookie']], raw: true);
    [, $denied] = oauthHttp($path, 302, http_build_query(['decision' => 'deny', '_token' => $browser['csrf']]), $headers, raw: true);
    $denial = oauthConsentRedirect($denied, $fixture['redirect'], 'browser-deny');
    ensure(($denial['error'] ?? null) === 'access_denied' && !isset($denial['code']), 'Denied authorization issued a code.');
    $parameters['state'] = 'browser-approve';
    $path = $route . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    $invalid = $parameters;
    $invalid['redirect_uri'] = 'https://foreign.example.test/callback';
    [, $rejected] = oauthHttp($route . '?' . http_build_query($invalid, '', '&', PHP_QUERY_RFC3986), 400, headers: ['Cookie: ' . $browser['cookie']], raw: true);
    ensure(!isset($rejected['location']), 'Unregistered redirect was followed.');
    [, $approved] = oauthHttp($path, 302, $form, $headers, raw: true);
    $approval = oauthConsentRedirect($approved, $fixture['redirect'], 'browser-approve');
    ensure(is_string($approval['code'] ?? null) && $approval['code'] !== '' && !isset($approval['error']), 'Consent approval did not issue a code.');
    echo "Authenticated consent page, browser isolation, CSRF/origin rejection, denial and approval passed\n";
    return ['code' => $approval['code'], 'verifier' => $verifier];
}

function oauthBrowserRedeem(array $fixture): void
{
    $form = http_build_query([
        'grant_type' => 'authorization_code', 'client_id' => $fixture['public_id'],
        'redirect_uri' => $fixture['redirect'], 'code' => $fixture['authorization']['code'],
        'code_verifier' => $fixture['authorization']['verifier'],
    ]);
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    [$token, $received] = oauthHttp('/oauth/token', 200, $form, $headers);
    oauthNoStore($received);
    ensure(is_string($token['access_token'] ?? null) && $token['access_token'] !== '', 'Consent code did not survive host replacement.');
    [$error, $received] = oauthHttp('/oauth/token', 400, $form, $headers);
    oauthNoStore($received);
    ensure($error['error'] === 'invalid_grant', 'Authorization code replay was not rejected.');
    echo "Durable consent code, PKCE exchange and replay rejection across host replacement passed\n";
}
