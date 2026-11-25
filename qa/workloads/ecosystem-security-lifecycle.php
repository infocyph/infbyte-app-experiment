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

function otpMovingRotationContracts(string $backend, string $kind): void
{
    $root = evidenceDirectory() . '/otp-moving-rotation';
    verify(mkdir($root, 0700), 'OTP fixture directory unavailable.');
    $options = new CacheOptions(integrityKey: random_bytes(32), failOpen: false);
    $factory = static fn() => sharedAtomicCache($backend, 'otp-moving-rotation', $root, $options);
    $oldSecret = TOTP::generateSecret(32);
    $newSecret = TOTP::generateSecret(32);
    $now = 1_800_000_000;
    $counterMode = $kind !== 'ocra-challenge';
    if ($kind === 'hotp') {
        $old = new \Infocyph\OTP\HOTP($oldSecret, 8, 'sha256');
        $new = new \Infocyph\OTP\HOTP($newSecret, 8, 'sha256');
        $plan = $old->planRotation($newSecret, 'account', 'Infbyte', initialCounter: 100, gracePeriodInSeconds: 60, now: $now);
    } else {
        $suite = $counterMode ? 'OCRA-1:HOTP-SHA256-8:C-QN08' : 'OCRA-1:HOTP-SHA256-8:QN08';
        $old = \Infocyph\OTP\OCRA::fromBase32($suite, $oldSecret);
        $new = \Infocyph\OTP\OCRA::fromBase32($suite, $newSecret);
        $plan = $old->planRotation($newSecret, 'account', 'Infbyte', gracePeriodInSeconds: 60, now: $now);
    }
    $proof = static function (string $generation, int $counter) use ($kind, $counterMode, $old, $new): string {
        $factor = $generation === 'old' ? $old : $new;
        return $kind === 'hotp' ? $factor->generate($counter) : $factor->generate(sprintf('%08d', $counter), counter: $counterMode ? $counter : null);
    };
    $verify = static function (string $generation, string $code, int $counter, int $at) use ($factory, $kind, $counterMode, $old, $new, $plan): bool {
        if ($generation === 'old' && !$plan->isDualSecretActive(new DateTimeImmutable('@' . $at))) {
            return false;
        }
        $factor = $generation === 'old' ? $old : $new;
        $id = 'account-' . $generation;
        return $kind === 'hotp'
            ? $factor->verifyWithResult($code, counter: $counter, cache: $factory(), factorId: $id)->matched
            : $factor->verifyWithResult($code, sprintf('%08d', $counter), counter: $counterMode ? $counter : null, cache: $factory(), factorId: $id, replayTtl: $counterMode ? null : 600)->matched;
    };
    $firstOld = $proof('old', 42);
    $firstNew = $proof('new', 100);
    verify($verify('old', $firstOld, 42, $now) && $verify('new', $firstNew, 100, $now), 'Independent generation overlap failed.');
    verify(!$verify('old', $firstOld, 42, $now) && !$verify('new', $firstNew, 100, $now), 'Overlap replay accepted.');
    verify($verify('old', $proof('old', 43), 43, $now + 59), 'Old generation rejected before boundary.');
    verify(!$verify('old', $proof('old', 44), 44, $now + 60), 'Old generation accepted at cutover.');
    $next = $proof('new', 101);
    $results = parallelRace(static fn(): int => (int) $verify('new', $next, 101, $now + 60), 'moving-cutover', 12);
    verify(array_sum($results) === 1 && !$verify('new', $next, 101, $now + 60), 'Cutover replay race differed.');
    if ($counterMode) {
        verify(!$verify('new', $proof('new', 99), 99, $now + 61), 'Counter regressed after generation cutover.');
    } else {
        verify($verify('new', $proof('new', 99), 99, $now + 61), 'Independent challenge was denied.');
    }
    echo "OTP $kind/$backend generation overlap, cutover, replay and moving-factor semantics passed\n";
}

function otpElectionContracts(bool $seed, bool $tls = false): void
{
    $backend = (string) getenv($tls ? 'LAB_TLS_BACKEND' : 'LAB_COUNTER_BACKEND');
    $port = $seed ? 17389 : 17390;
    $options = new CacheOptions(integrityKey: str_repeat('i', 32), failOpen: false);
    $factory = static fn() => $backend === 'redis'
        ? \Infocyph\CacheLayer\Cache\Cache::redis('otp-election', "redis://127.0.0.1:$port", options: $options)
        : \Infocyph\CacheLayer\Cache\Cache::valkey('otp-election', "valkey://127.0.0.1:$port", options: $options);
    if ($tls) {
        $factory = static fn() => labOtpTlsCache('otp-election', $options);
    }
    $path = (string) getenv('LAB_OTP_ELECTION_STATE');
    if ($seed) {
        $state = ['secret' => TOTP::generateSecret(32), 'challengeKey' => bin2hex(random_bytes(32)), 'ocra' => bin2hex(random_bytes(32))];
    } else {
        $state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
    $cache = $factory();
    $generic = new GenericOtp($cache, hex2bin($state['challengeKey']), ttlSeconds: 600, maxAttempts: 3);
    $hotp = new \Infocyph\OTP\HOTP($state['secret']);
    $totp = new TOTP($state['secret']);
    $mobile = new \Infocyph\OTP\MobileOTP(substr($state['ocra'], 0, 16), '1234');
    $ocra = new \Infocyph\OTP\OCRA('OCRA-1:HOTP-SHA256-8:C-QN08', hex2bin($state['ocra']));
    $challengeFactor = new \Infocyph\OTP\OCRA('OCRA-1:HOTP-SHA256-8:QN08', hex2bin($state['ocra']));
    $at = 1_800_000_000;
    $window = new VerificationWindow(0, 0);
    $check = static function (int $counter, int $time, string $challenge, bool $expected) use ($cache, $hotp, $totp, $mobile, $ocra, $challengeFactor, $window): void {
        verify($hotp->verifyWithResult($hotp->generate($counter), counter: $counter, cache: $cache, factorId: 'hotp')->matched === $expected, 'HOTP election mismatch.');
        verify($totp->verifyWithWindow($totp->generate($time), $time, $window, $cache, 'totp')->matched === $expected, 'TOTP election mismatch.');
        verify($mobile->verifyWithWindow($mobile->generate($time), $time, $window, cache: $cache, factorId: 'mobile')->matched === $expected, 'MobileOTP election mismatch.');
        verify($ocra->verifyWithResult($ocra->generate($challenge, counter: $counter), $challenge, counter: $counter, cache: $cache, factorId: 'ocra-counter')->matched === $expected, 'Counter OCRA election mismatch.');
        verify($challengeFactor->verifyWithResult($challengeFactor->generate($challenge), $challenge, cache: $cache, factorId: 'ocra-challenge', replayTtl: 600)->matched === $expected, 'Challenge OCRA election mismatch.');
    };
    if ($seed) {
        $check(42, $at, '12345678', true);
        $state['consumed'] = $generic->generate('consumed');
        verify($generic->verify('consumed', $state['consumed']), 'Challenge seed consumption failed.');
        $state['pending'] = $generic->generate('pending');
        $state['exhausted'] = $generic->generate('exhausted');
        $wrong = ($state['exhausted'][0] === '0' ? '1' : '0') . substr($state['exhausted'], 1);
        for ($i = 0; $i < 3; ++$i) {
            verify(!$generic->verify('exhausted', $wrong), 'Wrong challenge passed.');
        }
        verify(file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'Private election state write failed.');
        // WAIT on a separate connection does not prove the original client's writes;
        // a replication-offset barrier is enforced by the host before fencing.
    } else {
        $check(42, $at, '12345678', false);
        verify(!$generic->verify('consumed', $state['consumed']) && !$generic->verify('exhausted', $state['exhausted']), 'Promotion resurrected consumed/exhausted proof.');
        $results = parallelRace(static fn(): int => (int) (new GenericOtp($factory(), hex2bin($state['challengeKey']), ttlSeconds: 600, maxAttempts: 3))->verify('pending', $state['pending']), 'otp-promoted', 12);
        verify(array_sum($results) === 1, 'Pending challenge did not settle exactly once after promotion.');
        $check(43, $at + 30, '87654321', true);
        $check(43, $at + 30, '87654321', false);
    }
    echo "$backend OTP election phase " . ($seed ? 'seed' : 'verify') . " passed\n";
}

function mobileRotationContracts(string $backend, bool $overlap): void
{
    $root = evidenceDirectory() . '/mobile-rotation';
    verify(mkdir($root, 0700), 'Mobile rotation fixture unavailable.');
    $options = new CacheOptions(integrityKey: random_bytes(32), failOpen: false);
    $factory = static fn() => sharedAtomicCache($backend, 'mobile-rotation', $root, $options);
    // MobileOTP has no native rotation planner; generation selection belongs to the application.
    $factors = [
        'old' => new \Infocyph\OTP\MobileOTP('0123456789abcdef', '1234'),
        'new' => new \Infocyph\OTP\MobileOTP('fedcba9876543210', '5678'),
    ];
    $start = 1_800_000_000;
    $cutover = $start + ($overlap ? 60 : 0);
    $admit = static function (string $generation, string $proof, int $at, string $account = 'account') use ($factors, $factory, $cutover, $start): bool {
        if (!isset($factors[$generation]) || $at < $start || ($generation === 'old' && $at >= $cutover)) {
            return false;
        }
        return $factors[$generation]->verifyWithWindow(
            $proof,
            $at,
            new VerificationWindow(0, 0),
            cache: $factory(),
            factorId: $account . '-' . $generation,
        )->matched;
    };
    $oldProof = $factors['old']->generate($start);
    $newProof = $factors['new']->generate($start);
    verify($oldProof !== $newProof, 'Fixture generations collide.');
    verify(!$admit('unknown', $newProof, $start), 'Unknown generation accepted.');
    verify(!$admit('new', $oldProof, $start), 'Old proof admitted by replacement generation.');
    verify($admit('old', $oldProof, $start) === $overlap, 'Old generation admission policy changed.');
    verify($admit('new', $newProof, $start) && !$admit('new', $newProof, $start), 'Replacement generation replay boundary failed.');
    verify($admit('new', $newProof, $start, 'foreign') && !$admit('new', $newProof, $start, 'foreign'), 'Account replay isolation failed.');
    if ($overlap) {
        verify(!$admit('old', $oldProof, $start), 'Old generation replay admitted.');
        verify($admit('old', $factors['old']->generate($cutover - 1), $cutover - 1), 'Overlap closed prematurely.');
    }
    verify(!$admit('old', $factors['old']->generate($cutover), $cutover), 'Old generation admitted at cutover.');
    $at = $cutover + 10;
    $proof = $factors['new']->generate($at);
    $results = parallelRace(static fn(): int => (int) $admit('new', $proof, $at), 'mobile-cutover', 12);
    verify(array_sum($results) === 1 && !$admit('new', $proof, $at), 'MobileOTP cutover replay race differed.');
    verify($admit('new', $factors['new']->generate($at + 10), $at + 10), 'Next timestep unavailable after cutover.');
    echo "MobileOTP $backend application rotation policy and generation/account replay isolation completed\n";
}
