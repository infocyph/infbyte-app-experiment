<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Config\ConfigMerge;
use Infocyph\ArrayKit\Config\LayeredLazyFileConfig;
use Infocyph\Epicrypt\Certificate\CertificateChainVerifier;
use Infocyph\Epicrypt\Certificate\CertificateExpiry;
use Infocyph\Epicrypt\Certificate\CertificateFingerprint;
use Infocyph\Epicrypt\Certificate\CertificateInspector;
use Infocyph\Epicrypt\Certificate\CertificateKeyMatcher;
use Infocyph\Epicrypt\Certificate\CertificateOptions;
use Infocyph\Epicrypt\Certificate\CsrInspector;
use Infocyph\Epicrypt\Certificate\Enum\CertificateDigest;
use Infocyph\Epicrypt\Certificate\Enum\CertificatePurpose;
use Infocyph\Epicrypt\Certificate\Enum\ExtendedKeyUsage;
use Infocyph\Epicrypt\Certificate\Enum\KeyUsage;
use Infocyph\Epicrypt\Certificate\KeyPairGenerator;
use Infocyph\Epicrypt\Certificate\OpenSSL\CertificateAuthority;
use Infocyph\Epicrypt\Certificate\OpenSSL\CertificateBuilder;
use Infocyph\Epicrypt\Certificate\OpenSSL\CsrBuilder;
use Infocyph\Epicrypt\Certificate\Pkcs12;
use Infocyph\Epicrypt\DataProtection\ProtectionAlgorithm;
use Infocyph\Epicrypt\DataProtection\ProtectionOptions;
use Infocyph\Epicrypt\DataProtection\StringProtector;
use Infocyph\Epicrypt\Exception\EpicryptException;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyMaterialGenerator;
use Infocyph\InterMix\Serializer\ClosureSerializer;
use Infocyph\ReqShield\CompiledValidator;
use Infocyph\ReqShield\Exceptions\FrozenSchemaRegistryException;
use Infocyph\ReqShield\Rules\Callback;
use Infocyph\ReqShield\Schema\SchemaRegistry;
use Infocyph\ReqShield\Validator;
use Infocyph\UID\Configuration\SnowflakeConfig;
use Infocyph\UID\Exceptions\SequenceTimestampException;
use Infocyph\UID\Sequence\FilesystemSequenceProvider;
use Infocyph\UID\Snowflake;

function uidSequencesWorkload(): void
{
    $root = evidenceDirectory() . '/sequence-state';
    mkdir($root, 0700);
    $provider = new FilesystemSequenceProvider($root, 'ledger', 500000, 8);
    $timestamp = 100000;
    $first = $provider->next('fixed', 42, $timestamp);
    $counts = parallelRace(static function (int $worker) use ($provider, $root, $timestamp): int {
        $file = fopen($root . '/worker-' . $worker . '.jsonl', 'wb');
        verify(is_resource($file), 'Sequence worker evidence unavailable.');
        $config = new SnowflakeConfig(datacenterId: 2, workerId: 7, sequenceProvider: $provider);
        try {
            for ($i = 0; $i < 1000; ++$i) {
                $allocation = $provider->next('fixed', 42, $timestamp);
                $id = Snowflake::generateWithConfig($config);
                $parts = Snowflake::parse($id);
                verify($parts['datacenter_id'] === 2 && $parts['worker_id'] === 7, 'Node domain changed.');
                fwrite($file, json_encode([$allocation, $id], JSON_THROW_ON_ERROR) . "\n");
            }
        } finally {
            fclose($file);
        }

        return 1000;
    }, 'uid-sequence');
    verify(array_sum($counts) === 12000, 'Sequence workers lost work.');
    $allocations = [$first => true];
    $ids = [];
    for ($worker = 0; $worker < 12; ++$worker) {
        $stream = fopen($root . '/worker-' . $worker . '.jsonl', 'rb');
        verify(is_resource($stream), 'Missing sequence evidence.');
        try {
            while (($line = fgets($stream)) !== false) {
                [$allocation, $id] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                verify(!isset($allocations[$allocation]) && !isset($ids[$id]), 'Shared reservation or generated ID reused.');
                $allocations[$allocation] = true;
                $ids[$id] = true;
            }
        } finally {
            fclose($stream);
        }
    }
    verify(count($allocations) === 12001 && count($ids) === 12000, 'Exact ID reconciliation failed.');
    $replacement = new FilesystemSequenceProvider($root, 'ledger', 500000, 8);
    verify($replacement->next('fixed', 42, $timestamp) > max(array_keys($allocations)), 'Replacement reused reserved values.');
    expectFailure(static fn() => $replacement->next('fixed', 42, $timestamp - 1), SequenceTimestampException::class, 'Regressed timestamp accepted.');
    verify($replacement->next('fixed', 42, $timestamp + 1) === 1, 'New timestamp did not start a new sequence.');
    echo "12000 cross-process reservations and Snowflakes, fork/replacement isolation and clock regression passed\n";
}

function closureWorkload(): void
{
    $key = random_bytes(32);
    $serializer = ClosureSerializer::signed($key);
    $rate = 125;
    $closure = static fn(int $quantity): int => $quantity * $rate;
    $payload = $serializer->serialize($closure);
    $restored = $serializer->unserialize($payload);
    for ($i = 1; $i <= 1000; ++$i) {
        verify($restored($i) === $i * 125, 'Captured closure state changed.');
    }
    $parts = explode('.', $payload, 3);
    $parts[1][0] = $parts[1][0] === 'A' ? 'B' : 'A';
    expectFailure(static fn() => $serializer->unserialize(implode('.', $parts)), InvalidArgumentException::class, 'Tampered signature accepted.');
    expectFailure(static fn() => ClosureSerializer::signed(random_bytes(32))->unserialize($payload), InvalidArgumentException::class, 'Foreign signer accepted.');
    expectFailure(static fn() => ClosureSerializer::signed($key, 16)->unserialize($payload), InvalidArgumentException::class, 'Oversize envelope accepted.');
    expectFailure(static fn() => $serializer->unserialize(ClosureSerializer::serialize($closure)), InvalidArgumentException::class, 'Unsigned executable payload accepted by signed reader.');
    echo "Signed closure capture, tamper, wrong signer, size and unsigned rejection passed\n";
}

function configLayeringWorkload(): void
{
    $root = evidenceDirectory() . '/layering';
    mkdir($root, 0700);
    file_put_contents($root . '/app.php', "<?php return ['debug' => false, 'middleware' => ['auth', 'csrf'], 'nested' => ['source' => 2]];", LOCK_EX);
    $config = new LayeredLazyFileConfig(
        directory: $root,
        fallback: ['app' => ['debug' => true, 'middleware' => ['old'], 'nested' => ['default' => 1]]],
        overrides: ['app' => ['middleware' => ['api'], 'nested' => ['override' => 3]]],
        namespaces: ['app'],
    );
    verify($config->get('app.debug') === false && $config->get('app.middleware') === ['api'], 'Config precedence/list replacement failed.');
    verify($config->get('app.middleware.1', 'missing') === 'missing' && $config->get('app.nested') === ['default' => 1, 'source' => 2, 'override' => 3], 'Lower list index leaked or maps failed to merge.');
    verify($config->snapshot('before'), 'Snapshot failed.');
    verify($config->set('app.debug', true) && $config->get('app.debug') === true && $config->changed('before'), 'Runtime override failed.');
    verify($config->restore('before') && $config->get('app.debug') === false, 'Snapshot restore failed.');
    $merge = ConfigMerge::mergeMany([['a' => ['x' => 1], 'list' => [1, 2]], ['a' => ['y' => 2], 'list' => [3]], ['a' => false]]);
    verify($merge === ['a' => false, 'list' => [3]], 'Map/list/scalar merge precedence mismatch.');
    echo "Configuration fallback/source/override/runtime precedence, scalar/list shadowing and restore passed\n";
}

function pkiWorkload(): void
{
    $pass = bin2hex(random_bytes(24));
    $bundlePassword = bin2hex(random_bytes(24));
    $caKeys = KeyPairGenerator::rsa()->generate($pass);
    $ca = new CertificateBuilder()->selfSign(
        ['commonName' => 'Lab CA'],
        $caKeys['private'],
        passphrase: $pass,
        options: new CertificateOptions(days: 7, keyUsage: [KeyUsage::KEY_CERT_SIGN, KeyUsage::CRL_SIGN], isCa: true),
    );
    $keys = KeyPairGenerator::rsa()->generate($pass);
    $options = new CertificateOptions(days: 2, sanDns: ['service.example.test'], keyUsage: [KeyUsage::DIGITAL_SIGNATURE, KeyUsage::KEY_ENCIPHERMENT], extendedKeyUsage: [ExtendedKeyUsage::SERVER_AUTH]);
    $csr = new CsrBuilder()->build(['commonName' => 'service.example.test'], $keys['private'], $pass, $options);
    $certificate = new CertificateAuthority()->signCsr($csr, $ca, $caKeys['private'], $options, $pass);
    $verifier = new CertificateChainVerifier();
    verify($verifier->verify($certificate, [$ca], CertificatePurpose::SSL_SERVER), 'Issued chain failed verification.');
    verify(new CertificateKeyMatcher()->privateKeyMatches($certificate, $keys['private'], $pass), 'Leaf key mismatch.');
    verify(!new CertificateExpiry()->isExpired($certificate, leewaySeconds: 3600), 'Fresh leaf expired.');
    verify(new CertificateExpiry()->isExpired($certificate, leewaySeconds: 3 * 86400), 'Renewal threshold not detected.');
    $csrInfo = new CsrInspector()->inspect($csr);
    $certInfo = new CertificateInspector()->inspect($certificate);
    verify(hash_equals($csrInfo['public_key'], $certInfo['public_key']), 'Portable CSR/cert public key mismatch.');
    $wrong = KeyPairGenerator::rsa()->generate($pass);
    verify(!new CertificateKeyMatcher()->privateKeyMatches($certificate, $wrong['private'], $pass), 'Wrong private key matched.');
    $otherCa = new CertificateBuilder()->selfSign(['commonName' => 'Foreign CA'], $wrong['private'], passphrase: $pass, options: new CertificateOptions(days: 7, keyUsage: [KeyUsage::KEY_CERT_SIGN], isCa: true));
    verify(!$verifier->verify($certificate, [$otherCa], CertificatePurpose::SSL_SERVER), 'Foreign trust root accepted.');
    $pfx = new Pkcs12();
    $bundle = $pfx->export($certificate, $keys['private'], $bundlePassword, privateKeyPassphrase: $pass, friendlyName: 'lab-service', caCertificatesPem: [$ca]);
    $material = $pfx->import($bundle, $bundlePassword);
    verify(new CertificateKeyMatcher()->privateKeyMatches($material['certificate'], $material['private_key']), 'PFX key and leaf diverged.');
    $fingerprint = new CertificateFingerprint();
    verify($fingerprint->fingerprint($material['certificate'], CertificateDigest::SHA256) === $fingerprint->fingerprint($certificate, CertificateDigest::SHA256), 'PFX leaf identity changed.');
    expectFailure(static fn() => $pfx->import($bundle, 'wrong-password'), EpicryptException::class, 'Wrong PFX password accepted.');
    echo "CA/CSR/leaf issuance, portable parsing, trust/expiry/key checks and PKCS#12 roundtrip passed\n";
}

function cryptoAlgorithmsWorkload(): void
{
    foreach (ProtectionAlgorithm::cases() as $algorithm) {
        verify($algorithm->isAvailable(), 'Required hosted AEAD algorithm missing.');
        $protector = StringProtector::create($algorithm);
        $key = new KeyMaterialGenerator()->forAead();
        $options = new ProtectionOptions(purpose: 'lab-algorithms', additionalAuthenticatedData: 'tenant-1');
        for ($i = 0; $i < 250; ++$i) {
            $plain = random_bytes(512);
            $one = $protector->protect($plain, $key, $options);
            $two = $protector->protect($plain, $key, $options);
            verify($one !== $two && hash_equals($plain, $protector->unprotect($one, $key, $options)), 'Randomized protection roundtrip failed.');
            expectFailure(static fn() => $protector->unprotect($one, $key, new ProtectionOptions(purpose: 'lab-algorithms', additionalAuthenticatedData: 'tenant-2')), EpicryptException::class, 'Foreign AAD accepted.');
        }
    }
    echo "Both supported protection algorithms, randomized ciphertext and AAD isolation passed\n";
}

function validationRegistryWorkload(): void
{
    $registry = new SchemaRegistry(['orders' => ['email' => 'required|email', 'kind' => 'required|in:standard,express']]);
    $registry->extend('orders', [
        'quantity' => ['required', 'integer', new Callback(static fn(mixed $value): bool => is_numeric($value) && (int) $value % 2 === 0, cost: 5, message: 'Even quantities only')],
        'reference' => 'required_if:kind,express|string',
    ])->freeze();
    expectFailure(static fn() => $registry->remove('orders'), FrozenSchemaRegistryException::class, 'Frozen registry mutated.');
    $rules = $registry->get('orders');
    unset($rules['email']);
    verify(isset($registry->get('orders')['email']), 'Schema value escaped frozen registry.');
    $validator = Validator::make($registry->get('orders'))->stripUnknown()->setCasts(['quantity' => 'integer']);
    $compiled = new CompiledValidator($validator);
    for ($i = 0; $i < 500; ++$i) {
        $valid = $compiled->validate(['email' => 'test@example.test', 'kind' => 'express', 'quantity' => '4', 'reference' => 'order', 'admin' => true]);
        verify($valid->passes() && $valid->typed()['quantity'] === 4 && !isset($valid->safe()['admin']), 'Compiled registry valid projection failed.');
        $invalid = $compiled->validate(['email' => 'test@example.test', 'kind' => 'express', 'quantity' => '3']);
        verify($invalid->fails(), 'Conditional/custom validation did not reject invalid input.');
    }
    verify(isset($validator->exportSchema('json_schema')['properties'], $validator->exportSchema('openapi')['properties']), 'Schema export missing.');
    $limited = Validator::make(['items.*.id' => 'required|integer'])->limits(maxWildcardExpansions: 4);
    $rejected = false;
    try {
        $rejected = $limited->validate(['items' => array_fill(0, 20, ['id' => 1])])->fails();
    } catch (Infocyph\ReqShield\Exceptions\ValidationException) {
        $rejected = true;
    }
    verify($rejected, 'Wildcard expansion limit bypassed.');
    echo "Frozen registry isolation, custom/conditional rules, compiled reuse, schema exports and input bounds passed\n";
}
