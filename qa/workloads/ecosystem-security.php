<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Infocyph\Epicrypt\DataProtection\EnvelopeProtector;
use Infocyph\Epicrypt\DataProtection\FileProtector;
use Infocyph\Epicrypt\DataProtection\ProtectionOptions;
use Infocyph\Epicrypt\Exception\EpicryptException;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyMaterialGenerator;
use Infocyph\OTP\GenericOtp;
use Infocyph\OTP\RecoveryCodes;
use Infocyph\OTP\Stores\InMemoryRecoveryCodeStore;
use Infocyph\ReqShield\CompiledValidator;
use Infocyph\ReqShield\Validator;

function cacheTierWorkload(): void
{
    $lower = Cache::redis('lab-tier');
    $lower->set('only-l2', ['value' => 42], 60);
    $tier = Cache::tiered([
        ['driver' => 'memory', 'namespace' => 'lab-tier'],
        ['driver' => 'redis', 'namespace' => 'lab-tier'],
    ]);
    verify($tier->get('only-l2') === ['value' => 42], 'Lower-tier lookup failed.');
    $lower->delete('only-l2');
    verify($tier->get('only-l2') === ['value' => 42], 'Lower-tier hit was not promoted.');
    verify($tier->setMultiple(['one' => 1, 'two' => 2], 60), 'Tier bulk write failed.');
    verify($lower->get('one') === 1 && $lower->get('two') === 2, 'Tier write did not reach L2.');
    $tier->deleteMultiple(['one', 'two']);
    verify(!$lower->has('one') && !$tier->has('one') && !$lower->has('two'), 'Tier deletion did not converge.');
    echo "Tier promotion, write-through and bulk eviction passed\n";
}

function cacheLocalWorkload(): void
{
    foreach ([
        'memory' => Cache::memory('lab-memory'),
        'weak' => Cache::weakMap('lab-weak'),
        'apcu' => Cache::apcu('lab-apcu'),
        'shm' => Cache::sharedMemory('lab-shm'),
    ] as $name => $cache) {
        verify($cache->set('value', ['id' => 42], 60) && $cache->get('value') === ['id' => 42], "$name roundtrip failed.");
        $atomic = $cache->atomic();
        if ($name !== 'apcu') {
            verify($atomic !== null && $atomic->setIfAbsent('claim', 1, 60) && !$atomic->setIfAbsent('claim', 2, 60), "$name atomic claim failed.");
            verify(!$atomic->compareAndSet('claim', '1', 2, 60) && $atomic->compareAndSet('claim', 1, 2, 60), "$name strict CAS failed.");
            verify($atomic->getAndDelete('claim') === 2 && !$cache->has('claim'), "$name atomic consume failed.");
        }
        $cache->clear();
        verify(!$cache->has('value'), "$name clear failed.");
    }
    $weak = Cache::weakMap('lab-weak-objects', new CacheOptions(allowObjects: true));
    $object = new stdClass();
    $weak->set('object', $object, 60);
    verify($weak->get('object') === $object, 'Weak object identity lost.');
    unset($object);
    gc_collect_cycles();
    verify(!$weak->has('object'), 'Weak cache retained dead object.');
    $null = Cache::nullStore();
    $null->set('ignored', 1, 60);
    verify(!$null->has('ignored') && $null->get('ignored', 2) === 2 && $null->atomic() === null, 'Null store contract mismatch.');
    echo "Memory, weak references, APCu, shared memory and null contracts passed\n";
}

function cacheIntegrityWorkload(): void
{
    $key = random_bytes(32);
    $signed = Cache::redis('lab-integrity', options: new CacheOptions(integrityKey: $key, failOpen: false));
    verify($signed->set('record', ['amount' => 12], 60), 'Signed write failed.');
    verify($signed->get('record') === ['amount' => 12], 'Signed read failed.');
    $wrong = Cache::redis('lab-integrity', options: new CacheOptions(integrityKey: random_bytes(32)));
    verify($wrong->get('record', 'miss') === 'miss', 'Incorrect integrity key accepted record.');
    $unsigned = Cache::redis('lab-unsigned');
    $unsigned->set('record', ['amount' => 99], 60);
    $signedReader = Cache::redis('lab-unsigned', options: new CacheOptions(integrityKey: $key));
    verify($signedReader->get('record', 'miss') === 'miss', 'Unsigned record accepted by signed reader.');
    $bounded = Cache::memory('lab-bounded', new CacheOptions(maxPayloadBytes: 512, failOpen: false));
    expectFailure(static fn() => $bounded->set('invalid:key', 1), CacheInvalidArgumentException::class, 'Invalid PSR key accepted.');
    $rejected = false;
    try {
        $rejected = !$bounded->set('object', new stdClass());
    } catch (Exception) {
        $rejected = true;
    }
    verify($rejected && !$bounded->has('object'), 'Default serialization accepted object.');
    $rejected = false;
    try {
        $rejected = !$bounded->set('oversize', str_repeat('x', 4096));
    } catch (Exception) {
        $rejected = true;
    }
    verify($rejected && !$bounded->has('oversize'), 'Oversized payload accepted.');
    echo "Signed/unsigned boundary, wrong key, key validation and serialization limits passed\n";
}

/** @return list<int> */
function parallelRace(callable $operation, string $label, int $workers = 12): array
{
    $dir = evidenceDirectory();
    $pids = [];
    $barrier = $dir . '/' . $label . '-start';
    for ($id = 0; $id < $workers; ++$id) {
        $pid = pcntl_fork();
        verify($pid >= 0, 'Cannot fork race worker.');
        if ($pid === 0) {
            try {
                $deadline = hrtime(true) + 20_000_000_000;
                while (!is_file($barrier)) {
                    verify(hrtime(true) < $deadline, 'Race barrier timed out.');
                    usleep(1000);
                }
                $result = $operation($id);
                verify(file_put_contents($dir . '/' . $label . '-' . $id . '.json', json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'Race evidence write failed.');
                exit(0);
            } catch (Throwable $failure) {
                fwrite(STDERR, $failure::class . PHP_EOL);
                exit(1);
            }
        }
        $pids[] = $pid;
    }
    touch($barrier);
    $failed = false;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $failed = $failed || !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0;
    }
    verify(!$failed, 'Race worker failed.');
    $results = [];
    for ($id = 0; $id < $workers; ++$id) {
        $results[] = json_decode((string) file_get_contents($dir . '/' . $label . '-' . $id . '.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    return $results;
}

function cacheRaceWorkload(): void
{
    $results = parallelRace(static function (): int {
        $cache = Cache::redis('lab-atomic-race', options: new CacheOptions(failOpen: false));
        $atomic = $cache->atomic();
        verify($atomic !== null, 'Atomic capability absent.');
        $claim = $atomic->setIfAbsent('claim', 1, 60);
        $counters = AtomicCounters::redis('lab-atomic-race');
        for ($i = 0; $i < 100; ++$i) {
            $counters->increment('attempts', 1, 120);
        }

        return (int) $claim;
    }, 'cache-claim');
    verify(array_sum($results) === 1, 'Atomic claim had multiple or no winners.');
    $counters = AtomicCounters::redis('lab-atomic-race');
    verify($counters->increment('attempts', 1, 120)->value === 1201, 'Cross-process increments lost data.');
    Cache::redis('lab-atomic-race')->clear();
    verify($counters->increment('attempts', 1, 120)->value === 1202, 'Ordinary cache clear erased counter state.');
    echo "12-process one-winner claim and 1200 atomic increments reconciled\n";
}

function otpRaceWorkload(): void
{
    $key = random_bytes(32);
    $options = new CacheOptions(integrityKey: random_bytes(32), failOpen: false);
    $service = new GenericOtp(Cache::redis('lab-otp-race', options: $options), $key, maxAttempts: 3);
    $code = $service->generate('checkout');
    unset($service);
    $results = parallelRace(static fn(): int => (int) (new GenericOtp(Cache::redis('lab-otp-race', options: $options), $key, maxAttempts: 3))->verify('checkout', $code), 'otp-proof');
    verify(array_sum($results) === 1, 'Concurrent OTP proof did not have exactly one winner.');
    $service = new GenericOtp(Cache::redis('lab-otp-race', options: $options), $key, maxAttempts: 3);
    verify(!$service->verify('checkout', $code), 'Consumed proof still valid.');
    $code = $service->generate('checkout');
    $wrong = $code === '000000' ? '000001' : '000000';
    for ($i = 0; $i < 3; ++$i) {
        verify(!$service->verify('checkout', $wrong), 'Wrong proof accepted.');
    }
    verify(!$service->verify('checkout', $code), 'Attempt budget failed closed.');
    echo "12-process OTP replay race and failed-attempt exhaustion passed\n";
}

function otpRecoveryWorkload(): void
{
    $codes = new RecoveryCodes(new InMemoryRecoveryCodeStore(), random_bytes(32));
    $old = $codes->generate('customer-a');
    verify($old->remainingCount === 10, 'Recovery batch size mismatch.');
    $new = $codes->generate('customer-a');
    verify(!$codes->consume('customer-a', $old->plainCodes[0])->consumed, 'Regeneration left old code valid.');
    verify(!$codes->consume('customer-b', $new->plainCodes[0])->consumed, 'Recovery code crossed subject binding.');
    foreach ($new->plainCodes as $index => $code) {
        $result = $codes->consume('customer-a', strtolower($code));
        verify($result->consumed && $result->remainingCount === 9 - $index, 'Recovery code exhaustion mismatch.');
        verify(!$codes->consume('customer-a', $code)->consumed, 'Recovery code replay accepted.');
    }
    echo "Native recovery code replacement, binding, normalization and exhaustion passed\n";
}

function validationWorkload(): void
{
    $rules = ['user' => 'array', 'user.name' => 'required|string', 'email' => 'required|email', 'quantity' => 'required|integer|min:1|max:10', 'status' => 'required|in:pending,paid'];
    $builder = Validator::make($rules)->setSanitizers(['user' => ['jsonDecode'], 'email' => ['trim', 'lowercase']])->setCasts(['quantity' => 'integer'])->stripUnknown();
    $compiled = new CompiledValidator($builder);
    for ($i = 0; $i < 1000; ++$i) {
        $input = ['user' => '{"name":"ok","is_admin":true}', 'email' => ' TEST@example.test ', 'quantity' => '3', 'status' => 'paid'];
        $result = $compiled->validate($input);
        verify($result->passes(), 'Valid production input rejected.');
        verify($result->safe()['user'] === ['name' => 'ok'] && $result->typed()['quantity'] === 3, 'Projection or cast mismatch.');
        $invalid = $compiled->validate(array_replace($input, ['email' => 'invalid', 'quantity' => 99]));
        verify($invalid->fails() && $invalid->errorsFor('email') !== [] && $invalid->errorsFor('quantity') !== [], 'Invalid input passed or missing failure metadata.');
        verify(!$invalid->has('quantity'), 'Rejected quantity leaked to validated output.');
    }
    $strict = Validator::make(['user' => 'array', 'user.name' => 'required|string'])->setSanitizers(['user' => ['jsonDecode']])->strict();
    $result = $strict->validate(['user' => '{"name":"ok","is_admin":true}']);
    verify($result->fails() && !isset($result->safe()['user']['is_admin']), 'Strict decoded unknown field escaped.');
    $schema = $builder->exportSchema();
    verify($schema !== [], 'Schema export missing.');
    echo "2000 compiled input passes/failures, decoded unknown fields, casts and schema export passed\n";
}

function cryptoFileWorkload(): void
{
    $dir = evidenceDirectory() . '/crypto-private';
    mkdir($dir, 0700);
    $source = $dir . '/source.bin';
    $encrypted = $dir . '/protected.bin';
    $output = $dir . '/restored.bin';
    $handle = fopen($source, 'wb');
    verify(is_resource($handle), 'Cannot create fixture.');
    for ($i = 0; $i < 256; ++$i) {
        verify(fwrite($handle, random_bytes(65536)) === 65536, 'Fixture write failed.');
    }
    fclose($handle);
    $key = new KeyMaterialGenerator()->forAead();
    $options = new ProtectionOptions(purpose: 'document/v1', additionalAuthenticatedData: 'tenant=42;document=1');
    $protector = new FileProtector();
    try {
        $protector->protect($source, $encrypted, $key, $options);
        $protector->unprotect($encrypted, $output, $key, $options);
        verify(hash_file('sha256', $source) === hash_file('sha256', $output), 'SecretStream file reconciliation failed.');
        unlink($output);
        $stream = fopen($encrypted, 'r+b');
        verify(is_resource($stream) && fseek($stream, -1, SEEK_END) === 0, 'Cannot perturb fixture.');
        $byte = fread($stream, 1);
        verify(is_string($byte) && strlen($byte) === 1, 'Cannot read last byte.');
        fseek($stream, -1, SEEK_END);
        fwrite($stream, chr(ord($byte) ^ 1));
        fclose($stream);
        expectFailure(static fn() => $protector->unprotect($encrypted, $output, $key, $options), EpicryptException::class, 'Tampered ciphertext accepted.');
        verify(!is_file($output), 'Tampered ciphertext published plaintext.');
    } finally {
        foreach ([$source, $encrypted, $output] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }
    echo "16 MiB authenticated file stream roundtrip and tamper non-publication passed\n";
}

function cryptoEnvelopeWorkload(): void
{
    $protector = EnvelopeProtector::create();
    $key = new KeyMaterialGenerator()->forAead();
    $options = new ProtectionOptions(purpose: 'invoice/v1', additionalAuthenticatedData: 'tenant=42');
    $data = random_bytes(4096);
    $first = $protector->protect($data, $key, $options);
    $second = $protector->protect($data, $key, $options);
    verify($first !== $second && $protector->unprotect($first, $key, $options) === $data && $protector->unprotect($second, $key, $options) === $data, 'Envelope encryption or random data-key boundary failed.');
    $wrongContext = new ProtectionOptions(purpose: 'invoice/v1', additionalAuthenticatedData: 'tenant=43');
    expectFailure(static fn() => $protector->unprotect($first, $key, $wrongContext), EpicryptException::class, 'Cross-tenant AAD accepted.');
    $wrongKey = new KeyMaterialGenerator()->forAead();
    expectFailure(static fn() => $protector->unprotect($first, $wrongKey, $options), EpicryptException::class, 'Wrong master key accepted.');
    echo "Independent envelope encryption, context isolation and wrong-key rejection passed\n";
}
