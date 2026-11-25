<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Exception\ConfigurationException;
use Infocyph\Epicrypt\Exception\SigningKeyReadinessException;
use Infocyph\Epicrypt\Security\AsymmetricSigningKeySet;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\Enum\SymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\Jwks;
use Infocyph\Epicrypt\Token\Jwt\JwtClaims;
use Infocyph\Epicrypt\Token\Jwt\JwtPolicy;
use Infocyph\Epicrypt\Token\Jwt\SymmetricJwt;
use Infocyph\Epicrypt\Token\Payload\PurposeToken;
use Infocyph\Epicrypt\Token\Payload\PurposeTokenFailureReason;
use Infocyph\Epicrypt\Token\Payload\SignedPayload;

/** @return array{string,string} */
function rotationPair(AsymmetricJwtAlgorithm $algorithm): array
{
    if ($algorithm->isEdDsa()) {
        $pair = sodium_crypto_sign_keypair();

        return [sodium_crypto_sign_secretkey($pair), sodium_crypto_sign_publickey($pair)];
    }

    return josePemPair($algorithm->isRsa()
        ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]
        : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => match ($algorithm->value) {
            'ES256' => 'prime256v1',
            'ES384' => 'secp384r1',
            'ES512' => 'secp521r1',
        }]);
}

function jwtRotationWorkload(string $algorithmName): void
{
    $clock = new LabJwksClock(1800000000);
    $issuer = 'https://issuer.example';
    $algorithm = AsymmetricJwtAlgorithm::tryFrom($algorithmName) ?? SymmetricJwtAlgorithm::from($algorithmName);
    $asymmetric = $algorithm instanceof AsymmetricJwtAlgorithm;
    if ($asymmetric) {
        [$oldPrivate, $oldPublic] = rotationPair($algorithm);
        [$newPrivate, $newPublic] = rotationPair($algorithm);
    } else {
        $oldPrivate = $oldPublic = SymmetricJwt::generateBinaryKey($algorithm);
        $newPrivate = $newPublic = SymmetricJwt::generateBinaryKey($algorithm);
    }
    $class = $asymmetric ? AsymmetricJwt::class : SymmetricJwt::class;
    $policy = new JwtPolicy($issuer, 'orders', 'at+jwt', leewaySeconds: 0);
    $claims = JwtClaims::issue($issuer, 'customer-42', ['orders'], 120, ['scope' => 'orders:read'], clock: $clock);
    $oldToken = $class::issuer($oldPrivate, 'at+jwt', 'old', $algorithm, clock: $clock)->issue($claims);
    $newToken = $class::issuer($newPrivate, 'at+jwt', 'new', $algorithm, clock: $clock)->issue($claims);
    $entries = [
        new KeyRingEntry('old', $oldPublic, KeyStatus::FALLBACK, KeyPurpose::JWT_SIGNING, $algorithmName, notAfter: $clock->timestamp + 10, issuer: $issuer),
        new KeyRingEntry('new', $newPublic, KeyStatus::ACTIVE, KeyPurpose::JWT_SIGNING, $algorithmName, issuer: $issuer),
    ];
    $ring = new KeyRing($entries, clock: $clock);
    $verifier = $class::verifier($ring, $policy, $algorithm, clock: $clock);
    foreach (['old' => $oldToken, 'new' => $newToken] as $kid => $token) {
        $result = $verifier->verifyResult($token);
        verify($result->valid && $result->matchedKeyId === $kid && $result->claims['sub'] === 'customer-42', 'JWT rotation provenance mismatch.');
        verify(!$verifier->verify(joseTamper($token, 2)), 'Modified JWT accepted during rotation.');
    }
    $unknown = $class::issuer($newPrivate, 'at+jwt', 'unknown', $algorithm, clock: $clock)->issue($claims);
    verify(!$verifier->verify($unknown), 'Unknown kid accepted.');
    foreach ([
        new KeyRingEntry('new', $newPublic, KeyStatus::DISABLED, KeyPurpose::JWT_SIGNING, $algorithmName, issuer: $issuer),
        new KeyRingEntry('new', $newPublic, KeyStatus::ACTIVE, KeyPurpose::SIGNED_PAYLOAD, $algorithmName, issuer: $issuer),
        new KeyRingEntry('new', $newPublic, KeyStatus::ACTIVE, KeyPurpose::JWT_SIGNING, $algorithmName, issuer: 'https://foreign.example'),
        new KeyRingEntry('new', $newPublic, KeyStatus::ACTIVE, KeyPurpose::JWT_SIGNING, 'wrong-algorithm', issuer: $issuer),
    ] as $entry) {
        verify(!$class::verifier(new KeyRing([$entry], clock: $clock), $policy, $algorithm, clock: $clock)->verify($newToken), 'Ineligible JWT key accepted.');
    }
    $clock->timestamp += 9;
    verify($verifier->verify($oldToken), 'Fallback rejected before cutover.');
    ++$clock->timestamp;
    verify(!$verifier->verify($oldToken) && $verifier->verify($newToken), 'JWT rotation cutover incorrect.');
    echo $algorithmName . " JWT rotation and eligibility passed\n";
}

function signingReadinessWorkload(string $algorithmName): void
{
    $algorithm = AsymmetricJwtAlgorithm::from($algorithmName);
    [$oldPrivate, $oldPublic] = rotationPair($algorithm);
    [$newPrivate, $newPublic] = rotationPair($algorithm);
    $clock = new LabJwksClock(1800000000);
    $issuer = 'https://issuer.example';
    $ring = new KeyRing([
        new KeyRingEntry('old', $oldPublic, KeyStatus::FALLBACK, KeyPurpose::JWT_SIGNING, $algorithmName, notAfter: $clock->timestamp + 10, issuer: $issuer),
        new KeyRingEntry('new', $newPublic, KeyStatus::ACTIVE, KeyPurpose::JWT_SIGNING, $algorithmName, issuer: $issuer),
        new KeyRingEntry('disabled', $oldPublic, KeyStatus::DISABLED, KeyPurpose::JWT_SIGNING, $algorithmName, issuer: $issuer),
        new KeyRingEntry('foreign', $oldPublic, KeyStatus::ACTIVE, KeyPurpose::JWT_SIGNING, $algorithmName, issuer: 'https://foreign.example'),
    ], clock: $clock);
    $set = new AsymmetricSigningKeySet($issuer, 'new', $newPrivate, $ring, $algorithm);
    verify($set->activeMetadata()->id === 'new', 'Wrong signing key selected.');
    $jwks = $set->jwks();
    $ids = array_column($jwks['keys'], 'kid');
    sort($ids);
    verify($ids === ['new', 'old'], 'JWKS exposes ineligible keys or omits fallback.');
    foreach ($jwks['keys'] as $jwk) {
        verify(array_intersect(array_keys($jwk), ['d', 'p', 'q', 'dp', 'dq', 'qi', 'k', 'oth']) === [], 'Private material exposed in public JWKS.');
    }
    $jwk = new Jwks()->resolveByKid($jwks, 'new');
    $public = $algorithm->isEdDsa()
        ? new Jwks()->importOkpPublicKey($jwk, 'EdDSA', 'Ed25519')
        : new Jwks()->importPublicKeyFromJwk($jwk, $algorithm);
    $claims = JwtClaims::issue($issuer, 'customer-42', ['orders'], 120, clock: $clock);
    $token = AsymmetricJwt::issuer($set->privateKey(), 'at+jwt', 'new', $algorithm, clock: $clock)->issue($claims);
    verify(AsymmetricJwt::verifier($public, new JwtPolicy($issuer, 'orders', 'at+jwt'), $algorithm, clock: $clock)->verify($token), 'Published JWK cannot verify active signature.');
    expectFailure(static fn() => new AsymmetricSigningKeySet($issuer, 'new', $oldPrivate, $ring, $algorithm), SigningKeyReadinessException::class, 'Mismatched private/public pair accepted.');
    expectFailure(static fn() => new AsymmetricSigningKeySet($issuer, 'old', $oldPrivate, $ring, $algorithm), SigningKeyReadinessException::class, 'Fallback selected for writing.');
    expectFailure(static fn() => new AsymmetricSigningKeySet($issuer, 'new', $newPrivate, $ring, $algorithm, purpose: KeyPurpose::SIGNED_PAYLOAD), SigningKeyReadinessException::class, 'Wrong signing purpose accepted.');
    $clock->timestamp += 10;
    $rebuilt = new AsymmetricSigningKeySet($issuer, 'new', $newPrivate, $ring, $algorithm);
    verify(array_column($rebuilt->jwks()['keys'], 'kid') === ['new'], 'Rebuilt JWKS retains expired fallback.');
    echo $algorithmName . " signing readiness and public-key lifecycle passed\n";
}

function signedPayloadRotationWorkload(bool $purposeToken): void
{
    $clock = new LabJwksClock(1800000000);
    $oldKey = random_bytes(64);
    $newKey = random_bytes(64);
    $initial = new KeyRing([new KeyRingEntry('old', $oldKey, KeyStatus::ACTIVE, KeyPurpose::SIGNED_PAYLOAD, 'sha512')], clock: $clock);
    $ring = new KeyRing([
        new KeyRingEntry('old', $oldKey, KeyStatus::FALLBACK, KeyPurpose::SIGNED_PAYLOAD, 'sha512', notAfter: $clock->timestamp + 10),
        new KeyRingEntry('new', $newKey, KeyStatus::ACTIVE, KeyPurpose::SIGNED_PAYLOAD, 'sha512'),
    ], clock: $clock);
    if ($purposeToken) {
        $old = new PurposeToken($initial, 'order-confirmation', 'tenant-a', 60, $clock);
        $current = new PurposeToken($ring, 'order-confirmation', 'tenant-a', 60, $clock);
        $oldToken = $old->issue(['order' => 42], 'customer-42');
        $newToken = $current->issue(['order' => 43], 'customer-42', $clock->timestamp + 2);
        $result = $current->verify($oldToken);
        verify($result->verified && $result->matchedKeyId === 'old' && $result->usedFallbackKey && $result->claims['order'] === 42, 'Purpose-token fallback provenance incorrect.');
        verify($current->verify($newToken)->failureReason === PurposeTokenFailureReason::NOT_YET_VALID, 'Delayed token accepted early.');
        verify(new PurposeToken($ring, 'order-confirmation', 'tenant-b', 60, $clock)->verify($oldToken)->failureReason === PurposeTokenFailureReason::WRONG_CONTEXT, 'Foreign context accepted.');
        verify(new PurposeToken($ring, 'other-purpose', 'tenant-a', 60, $clock)->verify($oldToken)->failureReason === PurposeTokenFailureReason::WRONG_PURPOSE, 'Foreign purpose accepted.');
        expectFailure(static fn() => $current->issue(['kid' => 'old']), ConfigurationException::class, 'Caller replaced signing key metadata.');
        $clock->timestamp += 2;
        $result = $current->verify($newToken);
        verify($result->verified && $result->matchedKeyId === 'new' && !$result->usedFallbackKey, 'Activated token invalid.');
        $clock->timestamp += 8;
        verify(!$current->verify($oldToken)->verified && $current->verify($newToken)->verified, 'Purpose-token cutover incorrect.');
        $clock->timestamp += 50;
        verify($current->verify($newToken)->failureReason === PurposeTokenFailureReason::EXPIRED_TOKEN, 'Expired purpose token accepted.');
    } else {
        $payload = new SignedPayload('tenant-a', $clock);
        $oldToken = $payload->encode(['order' => 42], $oldKey, $clock->timestamp + 60);
        $newToken = $payload->encode(['order' => 43], $newKey, $clock->timestamp + 60);
        foreach (['old' => $oldToken, 'new' => $newToken] as $kid => $token) {
            $result = $payload->verifyWithAnyKeyDetailedResult($token, $ring);
            verify($result->verified && $result->matchedKeyId === $kid && $result->usedFallbackKey === ($kid === 'old'), 'Signed-payload key provenance incorrect.');
        }
        verify(!new SignedPayload('tenant-b', $clock)->verify($oldToken, $oldKey), 'Foreign signed-payload context accepted.');
        $clock->timestamp += 10;
        verify(!$payload->verifyWithAnyKey($oldToken, $ring) && $payload->verifyWithAnyKey($newToken, $ring), 'Signed-payload cutover incorrect.');
        $clock->timestamp += 50;
        verify($payload->verifyResult($newToken, $newKey)->expired, 'Signed-payload expiry not reported.');
    }
    echo "Signed token rotation and temporal boundaries passed\n";
}
