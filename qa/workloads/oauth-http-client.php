<?php

declare(strict_types=1);

namespace Experiment;

require getenv('PROJECT') . '/vendor/autoload.php';

function oauthHttp(string $path, int $status, ?string $body = null, array $headers = []): array
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
    return [$response === '' ? [] : json_decode($response, true, flags: JSON_THROW_ON_ERROR), $received];
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
    echo "Host replacement, authoritative introspection, idempotent revocation and fresh issuance passed\n";
}
