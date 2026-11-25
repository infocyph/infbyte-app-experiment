<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Exception\Token\InvalidTokenException;
use Infocyph\Epicrypt\Exception\Token\KeyResolutionException;
use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\Enum\JweKeyManagementAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\Enum\SymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\Jwe;
use Infocyph\Epicrypt\Token\Jwt\Jwks;
use Infocyph\Epicrypt\Token\Jwt\Jws;
use Infocyph\Epicrypt\Token\Jwt\JwtClaims;
use Infocyph\Epicrypt\Token\Jwt\JwtPolicy;
use Infocyph\Epicrypt\Token\Jwt\SymmetricJwt;

/** @return array{string,string} */
function josePemPair(array $options): array
{
    $key = openssl_pkey_new($options);
    verify($key !== false && openssl_pkey_export($key, $private), 'Disposable JOSE key generation failed.');
    $details = openssl_pkey_get_details($key);
    verify(is_array($details) && is_string($details['key'] ?? null), 'Public key extraction failed.');

    return [$private, $details['key']];
}

function joseTamper(string $token, int $segment): string
{
    $parts = explode('.', $token);
    verify(isset($parts[$segment][0]), 'Cannot perturb empty token segment.');
    $parts[$segment][0] = $parts[$segment][0] === 'A' ? 'B' : 'A';

    return implode('.', $parts);
}

function jwtJwsWorkload(): void
{
    $policy = new JwtPolicy('https://issuer.example', 'orders', 'at+jwt', leewaySeconds: 0);
    $wrongAudience = new JwtPolicy('https://issuer.example', 'foreign', 'at+jwt', leewaySeconds: 0);
    $claims = JwtClaims::issue('https://issuer.example', 'customer-42', ['orders'], 120, ['scope' => 'orders:read']);
    $future = JwtClaims::issue('https://issuer.example', 'customer-42', ['orders'], 120, activationDelaySeconds: 60);
    $payload = '{"order":42,"amount":125}';
    $jwks = new Jwks();
    $signers = [];
    $verifiers = [];

    foreach (SymmetricJwtAlgorithm::cases() as $algorithm) {
        $key = SymmetricJwt::generateBinaryKey($algorithm);
        $issuer = SymmetricJwt::issuer($key, 'at+jwt', algorithm: $algorithm);
        $verifier = SymmetricJwt::verifier($key, $policy, $algorithm);
        $token = $issuer->issue($claims);
        verify($verifier->verify($token) && !$verifier->verify(joseTamper($token, 2)), 'Symmetric JWT integrity failed.');
        verify(!$verifier->verify($issuer->issue($future)), 'Inactive JWT accepted.');
        verify(!SymmetricJwt::verifier($key, $wrongAudience, $algorithm)->verify($token), 'Foreign audience accepted.');
        verify(!SymmetricJwt::verifier(SymmetricJwt::generateBinaryKey($algorithm), $policy, $algorithm)->verify($token), 'Foreign symmetric key accepted.');
        $jwk = $jwks->exportSymmetricSecretJwk($key, $algorithm->value, $algorithm->value);
        verify($jwks->importSymmetricKey($jwk, $algorithm->value) === $key, 'Symmetric JWK roundtrip failed.');
        $signers[] = Jws::signer($key, $algorithm, $algorithm->value);
        $verifiers[] = Jws::verifier($key, $algorithm, $algorithm->value);
        echo $algorithm->value . " JWT and JWK passed\n";
    }

    $rsa = josePemPair(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
    $ed = sodium_crypto_sign_keypair();
    $pairs = [
        'ES256' => josePemPair(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
        'ES384' => josePemPair(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']),
        'ES512' => josePemPair(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp521r1']),
        'EdDSA' => [sodium_crypto_sign_secretkey($ed), sodium_crypto_sign_publickey($ed)],
    ];
    foreach (AsymmetricJwtAlgorithm::cases() as $algorithm) {
        [$private, $public] = $algorithm->isRsa() ? $rsa : $pairs[$algorithm->value];
        $kid = $algorithm->value;
        $issuer = AsymmetricJwt::issuer($private, 'at+jwt', $kid, $algorithm);
        $token = $issuer->issue($claims);
        if ($algorithm->isEdDsa()) {
            $jwk = $jwks->exportOkpPublicKey($public, $kid);
            $reconstructed = $jwks->importOkpPublicKey($jwk, 'EdDSA', 'Ed25519');
        } else {
            $jwk = $jwks->exportPublicKeyToJwk($public, $kid, $algorithm);
            $reconstructed = $jwks->importPublicKeyFromJwk($jwk, $algorithm);
        }
        verify($jwks->resolveByKid(['keys' => [$jwk]], $kid) === $jwk && $jwks->thumbprint($jwk) !== '', 'JWKS lookup/thumbprint failed.');
        expectFailure(static fn() => $jwks->resolveByKid(['keys' => [$jwk]], 'missing'), KeyResolutionException::class, 'Unknown JWKS kid accepted.');
        $verifier = AsymmetricJwt::verifier($reconstructed, $policy, $algorithm);
        verify($verifier->verify($token) && !$verifier->verify(joseTamper($token, 2)), 'Asymmetric JWT integrity failed.');
        verify(!AsymmetricJwt::verifier($public, $wrongAudience, $algorithm)->verify($token), 'Asymmetric JWT audience crossed boundary.');
        verify(!$verifier->verify($issuer->issue($future)), 'Inactive asymmetric JWT accepted.');
        $signers[] = Jws::signer($private, $algorithm, $kid);
        $verifiers[] = Jws::verifier($reconstructed, $algorithm, $kid);
        echo $kid . " JWT and JWK passed\n";
    }

    foreach ($signers as $index => $signer) {
        $verifier = $verifiers[$index];
        $compact = $signer->signCompact($payload);
        verify($verifier->verifyCompact($compact) && !$verifier->verifyCompact(joseTamper($compact, 2)), 'Compact JWS integrity failed.');
        $flattened = $signer->signFlattened($payload, detached: true, base64Payload: false);
        verify($verifier->verifyFlattened($flattened, $payload) && !$verifier->verifyFlattened($flattened, $payload . 'x'), 'Detached flattened JWS integrity failed.');
    }
    $general = Jws::signGeneral($payload, [$signers[0], $signers[3]], detached: true);
    verify(Jws::verifyGeneral($general, [$verifiers[0], $verifiers[3]], 2, $payload), 'Mixed signature threshold rejected.');
    verify(!Jws::verifyGeneral($general, [$verifiers[0], $verifiers[4]], 2, $payload), 'Incorrect signer satisfied threshold.');
    verify(!Jws::verifyGeneral($general, [$verifiers[0], $verifiers[3]], 2, $payload . 'x'), 'Tampered detached payload satisfied threshold.');
    echo count($signers) . " JWT/JWS algorithm lanes, key exports and threshold verification passed\n";
}

function jweWorkload(): void
{
    $payload = random_bytes(16384);
    $symmetric = random_bytes(32);
    $rsa = josePemPair(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
    $x25519 = random_bytes(32);
    foreach (JweKeyManagementAlgorithm::cases() as $algorithm) {
        [$private, $public] = match ($algorithm) {
            JweKeyManagementAlgorithm::RSA_OAEP_256 => $rsa,
            JweKeyManagementAlgorithm::ECDH_ES, JweKeyManagementAlgorithm::ECDH_ES_A256KW => [$x25519, sodium_crypto_scalarmult_base($x25519)],
            default => [$symmetric, $symmetric],
        };
        $encrypt = new Jwe($public, $algorithm, keyId: 'recipient');
        $decrypt = new Jwe($private, $algorithm, keyId: 'recipient');
        for ($i = 0; $i < 4; ++$i) {
            $compact = $encrypt->encryptCompact($payload);
            verify($decrypt->decryptCompact($compact) === $payload, 'Compact JWE roundtrip failed.');
            verify($encrypt->encryptCompact($payload) !== $compact, 'Encryption repeated ciphertext.');
            expectFailure(static fn() => $decrypt->decryptCompact(joseTamper($compact, 4)), InvalidTokenException::class, 'Altered JWE tag accepted.');
            $flattened = $encrypt->encryptFlattened($payload);
            verify($decrypt->decryptFlattened($flattened) === $payload, 'Flattened JWE roundtrip failed.');
        }
        echo $algorithm->value . " compact and flattened JWE passed\n";
    }
    $second = random_bytes(32);
    $sender = new Jwe($symmetric, JweKeyManagementAlgorithm::A256KW);
    $general = $sender->encryptGeneral($payload, [['key' => $symmetric, 'kid' => 'first'], ['key' => $second, 'kid' => 'second']]);
    verify($sender->decryptGeneral($general, 'first') === $payload, 'First recipient failed.');
    verify(new Jwe($second, JweKeyManagementAlgorithm::A256KW)->decryptGeneral($general, 'second') === $payload, 'Second recipient failed.');
    expectFailure(static fn() => $sender->decryptGeneral($general, 'second'), InvalidTokenException::class, 'Wrong recipient key accepted.');
    expectFailure(static fn() => $sender->decryptGeneral($general, 'unknown'), InvalidTokenException::class, 'Unknown recipient accepted.');
    $signed = Jws::signer($symmetric, SymmetricJwtAlgorithm::HS256)->signCompact('nested payload');
    $nested = $sender->encryptNested($signed);
    $inner = $sender->decryptNested($nested);
    verify($inner === $signed && Jws::verifier($symmetric, SymmetricJwtAlgorithm::HS256)->verifyCompact($inner), 'Nested authenticated token failed.');
    echo "Six JWE key-management algorithms, two recipients and nested JWS passed\n";
}
