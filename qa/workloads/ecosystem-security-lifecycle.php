<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\Epicrypt\DataProtection\EnvelopeProtector;
use Infocyph\Epicrypt\DataProtection\FileProtector;
use Infocyph\Epicrypt\DataProtection\ProtectionAlgorithm;
use Infocyph\Epicrypt\DataProtection\ProtectionOptions;
use Infocyph\Epicrypt\Exception\EpicryptException;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyMaterialGenerator;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\OTP\GenericOtp;
use Infocyph\OTP\TOTP;
use Infocyph\OTP\ValueObjects\VerificationWindow;
use Infocyph\ReqShield\CompiledValidator;
use Infocyph\ReqShield\Validator;

function protectionRotationRings(KeyPurpose $purpose, string $algorithm, LabJwksClock $clock): array
{
    $old = new KeyMaterialGenerator()->forAead();
    $new = new KeyMaterialGenerator()->forAead();
    return [
        new KeyRing([new KeyRingEntry('old', $old, KeyStatus::ACTIVE, $purpose, $algorithm)], $clock),
        new KeyRing([
            new KeyRingEntry('old', $old, KeyStatus::FALLBACK, $purpose, $algorithm, notAfter: $clock->timestamp + 60),
            new KeyRingEntry('new', $new, KeyStatus::ACTIVE, $purpose, $algorithm),
        ], $clock),
        new KeyRing([new KeyRingEntry('old', $old, KeyStatus::ACTIVE, KeyPurpose::JWT_SIGNING, $algorithm)], $clock),
    ];
}

function envelopeRotationContracts(string $algorithm): void
{
    $cipher = ProtectionAlgorithm::from($algorithm);
    $protector = EnvelopeProtector::create($cipher);
    $clock = new LabJwksClock(1_800_000_000);
    [$original, $rotated, $foreign] = protectionRotationRings(KeyPurpose::ENVELOPE_PROTECTION, $algorithm, $clock);
    $options = new ProtectionOptions('receipt/v1', 'tenant=alpha');
    $data = random_bytes(4096);
    $old = $protector->protectWithKeyRing($data, $original, $options);
    $new = $protector->protectWithKeyRing($data, $rotated, $options);
    $read = $protector->unprotectWithKeyRing($old, $rotated, $options);
    verify($read->value === $data && $read->keyId === 'old' && $read->usedFallbackKey, 'Fallback envelope provenance differs.');
    $read = $protector->unprotectWithKeyRing($new, $rotated, $options);
    verify($read->value === $data && $read->keyId === 'new' && !$read->usedFallbackKey, 'Active envelope provenance differs.');
    expectFailure(static fn() => $protector->unprotectWithKeyRing($old, $foreign, $options), EpicryptException::class, 'Cross-purpose envelope accepted.');
    expectFailure(static fn() => $protector->unprotectWithKeyRing($old, $rotated, new ProtectionOptions('receipt/v1', 'tenant=beta')), EpicryptException::class, 'Cross-context envelope accepted.');
    $clock->timestamp += 60;
    expectFailure(static fn() => $protector->unprotectWithKeyRing($old, $rotated, $options), EpicryptException::class, 'Expired fallback envelope accepted.');
    verify($protector->unprotectWithKeyRing($new, $rotated, $options)->value === $data, 'New envelope failed after cutover.');
    echo "Envelope $algorithm rotation, provenance, purpose/AAD isolation and expiry passed\n";
}

function fileKeyRotationContracts(): void
{
    $root = evidenceDirectory() . '/file-key-rotation';
    verify(mkdir($root, 0700), 'Fixture directory unavailable.');
    $clock = new LabJwksClock(1_800_000_000);
    [$original, $rotated, $foreign] = protectionRotationRings(KeyPurpose::FILE_PROTECTION, 'xchacha20-poly1305-secretstream', $clock);
    $protector = new FileProtector($clock);
    $options = new ProtectionOptions('document/v1', 'tenant=alpha');
    $source = $root . '/input';
    $output = $root . '/output';
    $old = $root . '/old';
    $new = $root . '/new';
    $data = random_bytes(131073);
    verify(file_put_contents($source, $data) === strlen($data), 'Fixture write failed.');
    $protector->protectWithKeyRing($source, $old, $original, $options);
    $protector->protectWithKeyRing($source, $new, $rotated, $options);
    $read = $protector->unprotectWithKeyRing($old, $output, $rotated, $options);
    verify($read->keyId === 'old' && $read->usedFallbackKey && hash_file('sha256', $output) === hash('sha256', $data), 'Fallback file provenance/digest differs.');
    $read = $protector->unprotectWithKeyRing($new, $output, $rotated, $options);
    verify($read->keyId === 'new' && !$read->usedFallbackKey && hash_file('sha256', $output) === hash('sha256', $data), 'Active file provenance/digest differs.');
    verify(file_put_contents($output, 'previous') === 8, 'Existing output fixture failed.');
    expectFailure(static fn() => $protector->unprotectWithKeyRing($old, $output, $foreign, $options), EpicryptException::class, 'Cross-purpose protected file accepted.');
    $clock->timestamp += 60;
    expectFailure(static fn() => $protector->unprotectWithKeyRing($old, $output, $rotated, $options), EpicryptException::class, 'Expired file key accepted.');
    verify(file_get_contents($output) === 'previous', 'Rejected decryption replaced existing output.');
    $protector->unprotectWithKeyRing($new, $output, $rotated, $options);
    verify(hash_file('sha256', $output) === hash('sha256', $data), 'New file failed after cutover.');
    foreach ([$source, $output, $old, $new] as $path) {
        unlink($path);
    }
    echo "File key rotation, authenticated stream digest and rejection non-publication passed\n";
}

function otpChallengeReplacementContracts(string $backend): void
{
    $root = evidenceDirectory() . '/otp-replacement';
    verify(mkdir($root, 0700), 'OTP fixture root unavailable.');
    $options = new CacheOptions(integrityKey: random_bytes(32), failOpen: false);
    $factory = static fn() => sharedAtomicCache($backend, 'otp-replacement', $root, $options);
    $key = random_bytes(32);
    $a = new GenericOtp($factory(), $key, digits: 10, maxAttempts: 3);
    $b = new GenericOtp($factory(), $key, digits: 10, maxAttempts: 3);
    $old = $a->generate('account');
    do {
        $new = $b->generate('account');
    } while ($new === $old);
    verify(!$a->verify('account', $old), 'Superseded proof accepted.');
    $foreign = $a->generate('foreign');
    verify($b->verify('account', $new) && !$a->verify('account', $new) && $b->verify('foreign', $foreign), 'Replacement proof or independent binding failed.');
    $deleted = $a->generate('delete');
    verify($b->delete('delete') && $b->delete('delete') && !$a->verify('delete', $deleted), 'Cross-instance explicit deletion failed.');
    $reissued = $b->generate('delete');
    verify($a->verify('delete', $reissued), 'Deleted binding could not be reissued.');
    $proof = $a->generate('format');
    for ($i = 0; $i < 5; ++$i) {
        verify(!$b->verify('format', 'not-digits'), 'Malformed proof accepted.');
    }
    verify($a->verify('format', $proof), 'Malformed proof exhausted valid attempt budget.');
    echo "OTP $backend replacement, deletion, reissue, binding and format handling passed\n";
}

function otpRotationOrchestrationContracts(string $backend): void
{
    $root = evidenceDirectory() . '/otp-rotation';
    verify(mkdir($root, 0700), 'OTP fixture root unavailable.');
    $options = new CacheOptions(integrityKey: random_bytes(32), failOpen: false);
    $factory = static fn() => sharedAtomicCache($backend, 'otp-rotation', $root, $options);
    $oldSecret = TOTP::generateSecret(32);
    $newSecret = TOTP::generateSecret(32);
    $old = new TOTP($oldSecret, 8, 30, 'sha256');
    $new = new TOTP($newSecret, 8, 30, 'sha256');
    $now = 1_800_000_000;
    $plan = $old->planRotation($newSecret, 'account', 'Infbyte', 60, $now);
    // Application chooses generation; OTP independently enforces each generation's replay state.
    $verify = static function (string $generation, string $proof, int $at) use ($factory, $old, $new, $plan): bool {
        if ($generation === 'old' && !$plan->isDualSecretActive(new DateTimeImmutable('@' . $at))) {
            return false;
        }
        $factor = $generation === 'old' ? $old : $new;
        return $factor->verifyWithWindow($proof, $at, new VerificationWindow(0, 0), $factory(), 'account-' . $generation)->matched;
    };
    $oldProof = $old->generate($now);
    $newProof = $new->generate($now);
    verify($verify('old', $oldProof, $now) && $verify('new', $newProof, $now), 'Overlap did not admit independent generations.');
    verify(!$verify('old', $oldProof, $now) && !$verify('new', $newProof, $now), 'Replacement peer accepted replay.');
    verify($verify('old', $old->generate($now + 30), $now + 30), 'Old generation rejected inside overlap.');
    verify(!$verify('old', $old->generate($now + 60), $now + 60), 'Old generation accepted at cutover.');
    $proof = $new->generate($now + 60);
    $results = parallelRace(static fn(): int => (int) $verify('new', $proof, $now + 60), 'otp-cutover', 12);
    verify(array_sum($results) === 1 && !$verify('new', $proof, $now + 60), 'New generation cutover replay race violated one-winner invariant.');
    echo "OTP $backend application-selected generation overlap, cutover and replay race passed\n";
}

function validationAffixDigitsContracts(): void
{
    foreach ([
        ['starts_with:AB,CD', 'AB123', 'XX123'],
        ['ends_with:.pdf,.txt', 'report.txt', 'report.exe'],
        ['doesnt_start_with:tmp-,draft-', 'final-report', 'draft-report'],
        ['doesnt_end_with:.tmp,.bak', 'report.pdf', 'report.bak'],
        ['digits_between:2,4', '001', '00001'],
        ['min_digits:3', '000', '-123'],
        ['max_digits:4', '0001', '1.2'],
    ] as [$rule, $positive, $negative]) {
        validationPair($rule, $rule, ['v' => $positive], ['v' => $negative]);
    }
    echo "Multi-affix and digit-width rule parameter boundaries passed\n";
}

function validationConditionalContracts(): void
{
    $schema = [
        'kind' => 'required|in:physical,digital',
        'shipping' => 'required_if:kind,physical',
        'tracking' => 'exclude_if:kind,digital|string',
    ];
    $builder = Validator::make($schema)->setFailFast(false);
    foreach ([$builder, new CompiledValidator($builder)] as $validator) {
        $valid = $validator->validate(['kind' => 'physical', 'shipping' => 'address', 'tracking' => 'parcel']);
        verify($valid->passes() && $valid->safe()['tracking'] === 'parcel', 'Physical record excluded required data.');
        $invalid = $validator->validate(['kind' => 'physical', 'tracking' => 'parcel']);
        verify($invalid->fails() && $invalid->hasError('shipping'), 'Conditional requirement was bypassed.');
        $digital = $validator->validate(['kind' => 'digital', 'tracking' => ['untrusted']]);
        verify($digital->passes() && !array_key_exists('tracking', $digital->safe()), 'Excluded field was validated or leaked.');
        verify($validator->validate(['kind' => 'physical', 'shipping' => 'address', 'tracking' => 'parcel'])->passes(), 'Condition state leaked across requests.');
    }
    echo "Direct/compiled conditional requirements, exclusion and reused validator isolation passed\n";
}
