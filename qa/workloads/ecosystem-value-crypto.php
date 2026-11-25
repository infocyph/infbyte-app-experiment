<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Crypto\Mac;
use Infocyph\Epicrypt\Crypto\Signature;
use Infocyph\Epicrypt\Exception\ConfigurationException;
use Infocyph\Epicrypt\Exception\Crypto\InvalidKeyException;
use Infocyph\Epicrypt\Exception\Crypto\SignatureException;
use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\UID\Configuration\SnowflakeConfig;
use Infocyph\UID\Configuration\SonyflakeConfig;
use Infocyph\UID\Configuration\TBSLConfig;
use Infocyph\UID\Enums\SonyflakeFormat;
use Infocyph\UID\Id;
use Infocyph\UID\IdComparator;
use Infocyph\UID\KSUID;
use Infocyph\UID\ObjectID;
use Infocyph\UID\OpaqueId;
use Infocyph\UID\Sequence\InMemorySequenceProvider;
use Infocyph\UID\Sonyflake;
use Infocyph\UID\TBSL;
use Infocyph\UID\TypeID;
use Infocyph\UID\Value\TbslValue;
use Infocyph\UID\Value\UlidValue;
use Infocyph\UID\Value\UuidValue;
use Infocyph\UID\XID;

function uidValuesWorkload(): void
{
    $epoch = new DateTimeImmutable('2020-01-01T00:00:00Z');
    $now = time();
    $snow = Id::snowflakeValue(new SnowflakeConfig(
        datacenterId: 3, workerId: 5, customEpoch: $epoch, sequenceProvider: new InMemorySequenceProvider(),
    ));
    verify($snow->getMachineId() === '3:5' && $snow->getWorkerId() === 5 && $snow->getDatacenterId() === 3, 'Snowflake value metadata mismatch.');
    verify(abs($snow->getTimestamp()->getTimestamp() - $now) <= 5 && $snow->getVersion() === null && $snow->isSortable(), 'Snowflake epoch/time metadata mismatch.');
    $flakes = [];
    foreach (SonyflakeFormat::cases() as $format) {
        $config = new SonyflakeConfig(machineId: 42, customEpoch: $epoch, sequenceProvider: new InMemorySequenceProvider(), format: $format);
        $one = Id::sonyflakeValue($config);
        $two = Id::sonyflakeValue($config);
        verify($one->getMachineId() === 42 && $one->getFormat() === $format && abs($one->getTimestamp()->getTimestamp() - $now) <= 5, 'Sonyflake format/epoch metadata mismatch.');
        verify($one->compare($two) < 0 && $one->getVersion() === null && $one->isSortable(), 'Sonyflake order mismatch.');
        $flakes[] = [Sonyflake::class, $one->toString()];
    }
    $tbsl = new TbslValue(Id::tbsl(new TBSLConfig(machineId: 9)));
    verify($tbsl->getMachineId() === 9 && abs($tbsl->getTimestamp()->getTimestamp() - $now) <= 5 && $tbsl->isSortable(), 'TBSL value metadata mismatch.');
    $flakes[] = [TBSL::class, $tbsl->toString()];
    foreach ($flakes as [$type, $id]) {
        foreach ([10, 16, 32, 36, 58, 62] as $base) {
            verify($type::fromBase($type::toBase($id, $base), $base) === $id, 'Flake base conversion mismatch.');
        }
        verify($type::fromBytes($type::toBytes($id)) === $id, 'Flake byte conversion mismatch.');
        expectFailure(static fn() => $type::toBase($id, 7), InvalidArgumentException::class, 'Unsupported base accepted.');
    }
    $firstTime = new DateTimeImmutable('2025-01-01T00:00:00Z');
    $secondTime = $firstTime->modify('+1 second');
    $first = new UlidValue(Id::ulid($firstTime));
    $second = new UlidValue(Id::ulid($secondTime));
    verify($first->compare($second) < 0 && (string) $first === $first->toString() && $first->getTimestamp()->getTimestamp() === $firstTime->getTimestamp()
        && $first->getMachineId() === null && $first->getVersion() === null && $first->isSortable(), 'ULID value mismatch.');
    foreach ([1 => Id::uuid1(), 4 => Id::uuid4(), 6 => Id::uuid6(), 7 => Id::uuid7($firstTime), 8 => Id::uuid8()] as $version => $id) {
        $value = new UuidValue(strtoupper($id));
        verify($value->toString() === $id && $value->compare($id) === 0 && $value->getVersion() === $version
            && $value->isSortable() === in_array($version, [6, 7], true), 'UUID version/normalization/sortability mismatch.');
        if ($version === 4 || $version === 8) {
            verify($value->getTimestamp() === null, 'Random/custom UUID invented a timestamp.');
        }
        if ($version === 7) {
            verify($value->getTimestamp()?->getTimestamp() === $firstTime->getTimestamp(), 'UUID7 timestamp mismatch.');
        }
    }
    verify(IdComparator::sort(['100', '2', '18446744073709551616', 'a', '1', 'Z']) === ['1', '2', '100', '18446744073709551616', 'Z', 'a'], 'Mixed unsigned/text total ordering failed.');
    foreach ([KSUID::class => Id::ksuid($firstTime), ObjectID::class => Id::objectId($firstTime), XID::class => Id::xid()] as $type => $id) {
        verify($type::fromBytes($type::toBytes($id)) === $id, 'Identifier binary roundtrip failed.');
    }
    $uuid = Id::uuid7($firstTime);
    verify(TypeID::toUuid(TypeID::fromUuid('order', $uuid)) === $uuid, 'TypeID UUID roundtrip failed.');
    foreach ([0, 1, 2 ** 32, PHP_INT_MAX] as $value) {
        foreach (['', 'tenant-a', 'tenant-b'] as $salt) {
            verify(OpaqueId::toInt(OpaqueId::fromInt($value, $salt), $salt) === $value, 'Opaque integer roundtrip failed.');
        }
    }
    verify(OpaqueId::fromInt(42, 'tenant-a') !== OpaqueId::fromInt(42, 'tenant-b'), 'Opaque salt domain mismatch.');
    expectFailure(static fn() => OpaqueId::fromInt(-1), InvalidArgumentException::class, 'Negative opaque integer accepted.');
    expectFailure(static fn() => OpaqueId::toInt('!!!'), InvalidArgumentException::class, 'Malformed opaque token accepted.');
    expectFailure(static fn() => new UlidValue('invalid'), InvalidArgumentException::class, 'Invalid ULID value accepted.');
    echo "UID value metadata, mixed ordering, alternate bases, binary families and opaque integer boundaries passed\n";
}

function detachedCryptoWorkload(): void
{
    $encode = static fn(string $binary): string => rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    $decode = static function (string $text): string {
        $binary = base64_decode(strtr($text, '-_', '+/'), true);
        verify(is_string($binary), 'Invalid base64url result.');
        return $binary;
    };
    $pair = sodium_crypto_sign_seed_keypair(hex2bin('9d61b19deffd5a60ba844af492ec2cc444449c5697b326919703bac031cae7f60'));
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = sodium_crypto_sign_publickey($pair);
    $signer = new Signature();
    // RFC 8032 test 1: independently published Ed25519 empty-message signature.
    $known = hex2bin('e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e06522490155' . '5fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b');
    verify($decode($signer->signWithBinaryKey('', $secret)) === $known, 'Ed25519 known vector mismatch.');
    verify($signer->verify('', $encode($known), $encode($public)), 'Known vector rejected.');
    $otherPublic = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
    $mac = new Mac();
    $key = $mac->generateKey(false);
    foreach (['', "\x00\xff", str_repeat('receipt', 150000)] as $message) {
        $signature = $signer->signWithBinaryKey($message, $secret);
        verify($signature === $signer->sign($message, $encode($secret)), 'Binary/encoded signing diverged.');
        verify(sodium_crypto_sign_verify_detached($decode($signature), $message, $public), 'Native signature verification failed.');
        verify($signer->verifyWithBinaryKey($message, $encode(sodium_crypto_sign_detached($message, $secret)), $public), 'Native signature rejected.');
        verify(!$signer->verifyWithBinaryKey($message . 'x', $signature, $public) && !$signer->verifyWithBinaryKey($message, $signature, $otherPublic), 'Tampered signature or wrong key accepted.');
        $authenticator = $mac->generateWithBinaryKey($message, $key);
        verify($authenticator === $mac->generate($message, $encode($key)) && $decode($authenticator) === sodium_crypto_auth($message, $key), 'MAC interoperability failed.');
        verify($mac->verify($message, $authenticator, $encode($key)) && $mac->verifyWithBinaryKey($message, $authenticator, $key), 'Valid MAC rejected.');
        verify(!$mac->verifyWithBinaryKey($message . 'x', $authenticator, $key) && !$mac->verifyWithBinaryKey($message, $authenticator, $mac->generateKey(false)), 'Tampered MAC accepted.');
    }
    foreach (['', '!', $encode(str_repeat('x', 31)), $encode(str_repeat('x', 65))] as $invalid) {
        verify(!$signer->verifyWithBinaryKey('message', $invalid, $public) && !$mac->verifyWithBinaryKey('message', $invalid, $key), 'Malformed authentication proof accepted.');
    }
    expectFailure(static fn() => $signer->signWithBinaryKey('x', 'short'), SignatureException::class, 'Short signing key accepted.');
    expectFailure(static fn() => $signer->verifyWithBinaryKey('x', 'invalid', 'short'), SignatureException::class, 'Short verification key accepted.');
    expectFailure(static fn() => $mac->generateWithBinaryKey('x', 'short'), InvalidKeyException::class, 'Short MAC key accepted.');
    $tokens = new OpaqueToken();
    $seen = [];
    foreach ([43, 48, 128] as $length) {
        for ($i = 0; $i < 64; ++$i) {
            $token = $tokens->issue($length);
            verify(strlen($token) === $length && preg_match('/\A[A-Za-z0-9_-]+\z/D', $token) === 1, 'Opaque token shape mismatch.');
            $digest = $tokens->hash($token);
            verify($digest === sodium_bin2hex(sodium_crypto_generichash($token)) && $tokens->verify($token, $digest), 'Opaque digest interoperability failed.');
            $changed = ($token[0] === 'A' ? 'B' : 'A') . substr($token, 1);
            verify(!$tokens->verify($changed, $digest) && !$tokens->verify($token, strtoupper($digest)), 'Opaque proof boundary failed.');
            verify(!isset($seen[$digest]), 'Opaque token collision.');
            $seen[$digest] = true;
        }
    }
    foreach ([42, 129] as $length) {
        expectFailure(static fn() => $tokens->issue($length), ConfigurationException::class, 'Invalid token length accepted.');
    }
    expectFailure(static fn() => $tokens->hash('invalid'), ConfigurationException::class, 'Invalid opaque token hashed.');
    echo "Ed25519 RFC vector, native detached signature/MAC interoperability, malformed proofs and opaque token boundaries passed\n";
}
