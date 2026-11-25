<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\Foundation\Http\Resource\JsonResource;
use Infocyph\Foundation\Http\Resource\ResourceCollection;
use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Foundation\Security\Risk\RiskTelemetry;
use Infocyph\Webrick\Request\Support\IpCidr;

function telemetryContext(string $tenant = 'alpha', string $purpose = 'login', string $ip = '198.51.100.3', string $signature = 't13d1516h2_0123456789ab_0123456789ab'): RiskContext
{
    return RiskContext::fromTransport($tenant, $purpose, '192.0.2.1', [IpCidr::from('192.0.2.1/32')], [$ip], [$signature], ['64500']);
}

function foundationRiskIsolation(string $backend): void
{
    [$cache, $peer] = cacheContractPeers($backend, 'risk-isolation');
    $key = str_repeat('r', 32);
    $a = new RiskTelemetry($cache, $key, shared: true, concentrationAt: 3);
    $b = new RiskTelemetry($peer, $key, shared: true, concentrationAt: 3);
    $context = telemetryContext();
    $now = intdiv(time(), 60) * 60;
    $empty = ['familiar' => false, 'failedSignatureAttempts' => 0, 'concentrated' => false, 'reliable' => true];
    verify($a->observe($context, $now, 'account') === $empty, 'Qualified cold observation differs.');
    for ($i = 1; $i <= 3; ++$i) {
        verify($a->recordFailure($context, $now, 'account'), 'Failure accounting did not settle.');
        verify($b->observe($context, $now, 'account') === ['familiar' => false, 'failedSignatureAttempts' => $i, 'concentrated' => $i >= 3, 'reliable' => true], 'Threshold or independent observation differs.');
    }
    verify($a->recordSuccess($context) && $b->observe($context, $now)['familiar'] === true, 'Successful fingerprint familiarity not shared.');
    verify($b->observe(telemetryContext('beta'), $now, 'account') === $empty && $b->observe(telemetryContext(purpose: 'password-reset'), $now, 'account') === $empty, 'Tenant/purpose scope leaked.');
    verify($b->observe($context, $now + 60, 'account') === ['familiar' => true, 'failedSignatureAttempts' => 0, 'concentrated' => false, 'reliable' => true], 'Fixed-window reset lost familiarity or retained count.');
    $different = telemetryContext(ip: '203.0.113.3', signature: 't13d1516h2_abcdef012345_0123456789ab');
    verify($b->observe($different, $now, 'account')['failedSignatureAttempts'] === 3 && $b->observe($different, $now, 'other')['failedSignatureAttempts'] === 0, 'Account dimension or signature separation differs.');
    $local = new RiskTelemetry($peer, $key, shared: false);
    verify(!$local->observe($context, $now)['reliable'] && $local->observe(telemetryContext('beta'), $now)['familiar'] === null, 'Unqualified absence claimed as reliable.');
    $direct = RiskContext::fromTransport('alpha', 'login', '198.51.100.3', [], ['203.0.113.3'], [$context->ja4], ['64500']);
    verify(!$direct->attested && $direct->ja4 === null && $direct->asn === null && $b->observe($direct, $now)['familiar'] === null, 'Untrusted signature influenced telemetry.');
    echo "Foundation risk telemetry $backend isolation, windows and trust boundaries passed\n";
}

function foundationRiskConcurrent(string $backend): void
{
    $root = evidenceDirectory() . '/risk-concurrent';
    verify(mkdir($root, 0700), 'Risk fixture directory unavailable.');
    $key = str_repeat('s', 32);
    $now = intdiv(time(), 60) * 60;
    $namespace = 'risk-concurrent';
    $results = parallelRace(static function () use ($backend, $root, $key, $now, $namespace): int {
        $cache = sharedAtomicCache($backend, $namespace, $root);
        $telemetry = new RiskTelemetry($cache, $key, shared: true, concentrationAt: 12);
        return (int) $telemetry->recordFailure(telemetryContext(), $now, 'account');
    }, 'risk-concurrent', 12);
    verify(count($results) === 12 && array_sum($results) === 12, 'Concurrent telemetry returned incomplete accounting.');
    $cache = sharedAtomicCache($backend, $namespace, $root);
    $peer = new RiskTelemetry($cache, $key, shared: true, concentrationAt: 12);
    verify($peer->observe(telemetryContext(), $now, 'account') === ['familiar' => false, 'failedSignatureAttempts' => 12, 'concentrated' => true, 'reliable' => true], 'Independent observer lost or duplicated failures.');
    verify($peer->observe(telemetryContext('beta'), $now, 'account')['failedSignatureAttempts'] === 0, 'Concurrent failures crossed tenant.');
    verify($peer->recordSuccess(telemetryContext()) && $peer->observe(telemetryContext(), $now, 'account')['failedSignatureAttempts'] === 12, 'Familiarity changed failure accounting.');
    echo "Foundation risk telemetry $backend twelve-process failure accounting passed\n";
}

function foundationResourceContracts(): void
{
    $seen = 0;
    $source = (static function (): Generator {
        yield 100 => ['id' => 1, 'private' => 'never-export'];
        yield 200 => ['id' => 2, 'private' => 'never-export'];
    })();
    $resource = new ResourceCollection($source, static function (array $row) use (&$seen): JsonResource {
        ++$seen;
        return new class($row) extends JsonResource {
            public function resolve(): array
            {
                return ['id' => $this->resource['id']];
            }
        };
    });
    verify($seen === 0, 'Resource eagerly consumed input.');
    verify(json_encode($resource, JSON_THROW_ON_ERROR) === '[{"id":1},{"id":2}]' && $seen === 2, 'Resource projection, cardinality or private field exclusion failed.');
    verify(json_encode(new ResourceCollection([], static fn($value) => $value), JSON_THROW_ON_ERROR) === '[]', 'Empty resource collection differs.');
    $failure = new DomainException('transform failure');
    expectFailure(static fn() => json_encode(new ResourceCollection([1], static fn() => throw $failure), JSON_THROW_ON_ERROR), DomainException::class, 'Resource suppressed transformer failure.');
    echo "Foundation resource lazy input, explicit projection and failure propagation passed\n";
}

function foundationRiskQualificationContracts(string $backend): void
{
    $root = evidenceDirectory() . '/risk-qualification';
    verify(mkdir($root, 0700), 'Risk fixture directory unavailable.');
    $namespace = 'risk-qualification';
    $strict = sharedAtomicCache($backend, $namespace, $root);
    $open = sharedAtomicCache($backend, $namespace, $root, new CacheOptions(failOpen: true));
    $key = str_repeat('q', 32);
    $context = telemetryContext();
    $now = intdiv(time(), 60) * 60;
    $local = new RiskTelemetry($strict, $key, shared: false, concentrationAt: 2);
    $failOpen = new RiskTelemetry($open, $key, shared: true, concentrationAt: 2);
    $qualified = new RiskTelemetry($strict, $key, shared: true, concentrationAt: 2);
    foreach ([$local, $failOpen] as $observer) {
        verify($observer->observe($context, $now, 'account') === ['familiar' => null, 'failedSignatureAttempts' => 0, 'concentrated' => false, 'reliable' => false], 'Unqualified observer claimed reliable absence.');
    }
    verify($strict->isAuthoritative() && !$strict->isFailOpen() && $open->isFailOpen(), 'Expected authoritative strict and fail-open fixture capabilities absent.');
    verify($qualified->observe($context, $now)['reliable'], 'Strict shared observer did not qualify.');
    verify($failOpen->recordFailure($context, $now, 'account') && $local->recordFailure($context, $now, 'account') && $local->recordSuccess($context), 'Available unqualified telemetry lost positive observations.');
    foreach ([$local, $failOpen] as $observer) {
        verify($observer->observe($context, $now, 'account') === ['familiar' => true, 'failedSignatureAttempts' => 2, 'concentrated' => true, 'reliable' => false], 'Positive observation or qualification differs.');
    }
    verify($qualified->observe($context, $now, 'account') === ['familiar' => true, 'failedSignatureAttempts' => 2, 'concentrated' => true, 'reliable' => true], 'Strict peer did not reconcile confirmed observations.');
    verify($qualified->observe(telemetryContext('foreign'), $now, 'account')['failedSignatureAttempts'] === 0, 'Unqualified writer crossed tenant.');
    echo "Foundation risk $backend local/fail-open qualification and positive evidence passed\n";
}

function foundationRiskCorruptionContracts(string $backend): void
{
    [$cache, $peer] = cacheContractPeers($backend, 'risk-corruption');
    $secret = str_repeat('c', 32);
    $context = telemetryContext();
    $now = intdiv(time(), 60) * 60;
    $bucket = (string) intdiv($now, 60);
    // Fault injection uses the published implementation's domain-separated storage key.
    // The public telemetry API alone cannot create malformed backing records.
    $key = static function (string $dimension, string $value, string $window = '') use ($secret, $context): string {
        $material = "foundation-risk-telemetry-v1\0" . $context->tenant . "\0" . $context->purpose . "\0" . $dimension . "\0" . $value . "\0" . $window;
        return 'rt.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $material, $secret, true)), '+/', '-_'), '=');
    };
    $signature = $key('signature', $context->ja4, $bucket);
    $familiar = $key('familiar', $context->ja4);
    $a = new RiskTelemetry($cache, $secret, shared: true, concentrationAt: 2);
    $b = new RiskTelemetry($peer, $secret, shared: true, concentrationAt: 2);
    verify($a->recordFailure($context, $now, 'account') && $a->recordSuccess($context), 'Risk corruption seed failed.');
    verify($peer->get($signature) === 1 && $peer->get($familiar) === 1, 'Fault injection did not identify the actual records.');
    foreach (['1', false, 0, -1, 1_000_001, ['bad' => 1]] as $invalid) {
        verify($cache->set($signature, $invalid, 600), 'Corrupt record injection failed.');
        $observed = $b->observe($context, $now, 'account');
        verify(!$observed['reliable'] && $observed['failedSignatureAttempts'] >= 1 && $observed['familiar'] === true, 'Corruption erased independent positive observation or retained reliability.');
        verify(!$a->recordFailure($context, $now, 'account') && $peer->get($signature) === $invalid, 'Invalid counter was overwritten or reported complete.');
    }
    verify($cache->set($signature, 1_000_000, 600) && $a->recordFailure($context, $now, 'account') && $peer->get($signature) === 1_000_000, 'Saturation did not preserve upper bound.');
    verify($cache->set($familiar, 2, 600), 'Malformed familiarity injection failed.');
    $observed = $b->observe($context, $now, 'account');
    verify($observed['familiar'] === null && !$observed['reliable'], 'Malformed familiarity became trusted.');
    verify($a->recordSuccess($context) && $b->observe($context, $now)['familiar'] === true && $b->observe($context, $now)['reliable'], 'Confirmed success did not recover valid familiarity.');
    echo "Foundation risk $backend corrupt state, partial accounting, saturation and recovery passed\n";
}

function foundationRiskOutageContracts(string $backend): void
{
    $root = (string) getenv('LAB_CACHE_FAULT_ROOT');
    $make = static fn(bool $open): Cache => match ($backend) {
        'redis' => Cache::redis('risk-outage', 'redis://127.0.0.1:17389', options: new CacheOptions(failOpen: $open)),
        'valkey' => Cache::valkey('risk-outage', 'valkey://127.0.0.1:17389', options: new CacheOptions(failOpen: $open)),
        'memcached' => Cache::memcached('risk-outage', [['127.0.0.1', 17389, 0]], options: new CacheOptions(failOpen: $open)),
        default => throw new InvalidArgumentException('Unknown risk outage backend.'),
    };
    $secret = str_repeat('o', 32);
    $context = telemetryContext();
    $now = intdiv(time(), 3600) * 3600;
    $strict = new RiskTelemetry($make(false), $secret, shared: true, windowSeconds: 3600);
    $open = new RiskTelemetry($make(true), $secret, shared: true, windowSeconds: 3600);
    verify($strict->recordFailure($context, $now, 'account') && $strict->recordSuccess($context), 'Risk outage seed failed.');
    verify($strict->observe($context, $now, 'account') === ['familiar' => true, 'failedSignatureAttempts' => 1, 'concentrated' => false, 'reliable' => true], 'Seed observation mismatch.');
    verify(touch($root . '/seeded'), 'Could not publish readiness.');
    cacheWaitMarker($root . '/stopped');
    foreach ([$strict, $open] as $observer) {
        $observation = $observer->observe($context, $now, 'account');
        verify(!$observation['reliable'] && $observation['familiar'] === null, 'Outage became a reliable observation.');
        verify(!$observer->recordFailure($context, $now, 'account') && !$observer->recordSuccess(telemetryContext('outage-only')), 'Unavailable telemetry reported complete mutation.');
    }
    verify(touch($root . '/observed'), 'Could not publish outage evidence.');
    cacheWaitMarker($root . '/restarted');
    $replacement = new RiskTelemetry($make(false), $secret, shared: true, windowSeconds: 3600);
    $durable = $backend !== 'memcached';
    verify($replacement->observe($context, $now, 'account') === ['familiar' => $durable, 'failedSignatureAttempts' => (int) $durable, 'concentrated' => false, 'reliable' => true], 'Restart lost confirmed durable evidence or invented volatile state.');
    verify($replacement->observe(telemetryContext('outage-only'), $now)['familiar'] === false, 'Rejected outage mutation appeared after restart.');
    verify($replacement->recordFailure($context, $now, 'account') && $replacement->observe($context, $now, 'account')['failedSignatureAttempts'] === 1 + (int) $durable, 'Post-restart accounting did not recover.');
    echo "Actual $backend outage, unavailable telemetry and durable/volatile recovery passed\n";
}

/** Build through Foundation's registered service providers, not manually wired specialist objects. */
function foundationWebhookApplication(string $backend, string $root, array $secrets, string $namespace): \Infocyph\Foundation\Application\Application
{
    require_once __DIR__ . '/bootstrap.php';
    putenv('AUTH_TOKEN_SECRET=' . $secrets[0]);
    $config = \Experiment\configuration();
    $config['app']['capabilities'] = ['cache', 'communication', 'database'];
    $store = [
        'driver' => $backend === 'phpfiles' ? 'php_files' : $backend,
        'namespace' => 'foundation-webhook',
        'fail_open' => false,
    ];
    $store += match ($backend) {
        'redis' => ['dsn' => 'redis://127.0.0.1:6379'],
        'valkey' => ['dsn' => 'valkey://127.0.0.1:6380'],
        'memcached' => ['servers' => [['127.0.0.1', 11211, 0]]],
        'file', 'phpfiles' => ['directory' => $root . '/records'],
        'sqlite' => ['sqlite_file' => $root . '/records.sqlite'],
        'pdo' => ['connection' => 'main'],
        default => throw new InvalidArgumentException('Unknown replay backend.'),
    };
    $config['cache']['stores']['lab-replay'] = $store;
    $config['communication']['webhooks']['default_inbound'] = 'lab';
    $config['communication']['webhooks']['inbound']['lab'] = [
        'secret' => $secrets,
        'max_age_seconds' => 300,
        'max_payload_bytes' => 2048,
        'replay' => ['enabled' => true, 'store' => 'lab-replay', 'ttl_seconds' => 600, 'namespace' => $namespace],
    ];
    return \Infocyph\Foundation\Foundation::worker($config)->boot();
}

function foundationWebhookHeaders(string $secret, string $body, string $id, int $timestamp, string $event = 'orders.created'): array
{
    $signature = new \Infocyph\TalkingBytes\Webhook\Model\WebhookSignature($secret);
    return [
        'X-TB-Event' => $event,
        'X-TB-Delivery' => $id,
        'X-TB-Timestamp' => (string) $timestamp,
        'X-TB-Signature' => $signature->buildHeader($body, $timestamp, $event, $id),
    ];
}

function rejectFoundationWebhook(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException $failure) {
        verify(str_contains($failure->getMessage(), $message), 'Unexpected webhook rejection: ' . $failure::class);
        return;
    }
    throw new LogicException('Expected webhook rejection missing: ' . $message);
}

function foundationWebhookReplayContracts(string $backend): void
{
    $root = evidenceDirectory() . '/foundation-webhook';
    verify(mkdir($root, 0700), 'Webhook fixture directory unavailable.');
    $secret = bin2hex(random_bytes(32));
    $body = '{"order":"A","amount":42}';
    $at = time();
    $headers = foundationWebhookHeaders($secret, $body, 'delivery-race', $at);
    $results = parallelRace(static function () use ($backend, $root, $secret, $body, $headers): int {
        $app = foundationWebhookApplication($backend, $root, [$secret], 'orders');
        return $app->execution()->run(static function () use ($app, $body, $headers): int {
            $receiver = $app->make(\Infocyph\TalkingBytes\Webhook\WebhookReceiver::class);
            try {
                $event = $receiver->receive($body, $headers);
                verify($event->payload === ['order' => 'A', 'amount' => 42], 'Verified webhook payload differs.');
                return 1;
            } catch (RuntimeException $failure) {
                if (!str_contains($failure->getMessage(), 'already been processed')) {
                    throw $failure;
                }
                return 0;
            }
        });
    }, 'foundation-webhook-replay', 12);
    verify(count($results) === 12 && array_sum($results) === 1, 'Configured replay provider did not admit exactly one worker.');
    $replacement = foundationWebhookApplication($backend, $root, [$secret], 'orders');
    $receiver = $replacement->make(\Infocyph\TalkingBytes\Webhook\WebhookReceiver::class);
    rejectFoundationWebhook(static fn() => $receiver->receive($body, $headers), 'already been processed');
    $fresh = foundationWebhookHeaders($secret, $body, 'delivery-fresh', $at);
    rejectFoundationWebhook(static fn() => $receiver->receive($body, array_replace($fresh, ['X-TB-Event' => 'orders.deleted'])), 'Webhook verification failed:');
    rejectFoundationWebhook(static fn() => $receiver->receive('{"order":"foreign"}', $fresh), 'Webhook verification failed:');
    verify($receiver->receive($body, $fresh)->deliveryId === 'delivery-fresh', 'Invalid proof consumed valid delivery.');
    echo "Foundation configured $backend protected webhook, 12-process claim and authenticated envelope passed\n";
}

function foundationWebhookRotationContracts(string $backend): void
{
    $root = evidenceDirectory() . '/foundation-webhook-rotation';
    verify(mkdir($root, 0700), 'Webhook rotation fixture directory unavailable.');
    $old = bin2hex(random_bytes(32));
    $new = bin2hex(random_bytes(32));
    $body = '{"order":"B"}';
    $at = time();
    $app = foundationWebhookApplication($backend, $root, [$old], 'orders');
    $receiver = $app->make(\Infocyph\TalkingBytes\Webhook\WebhookReceiver::class);
    $prior = foundationWebhookHeaders($old, $body, 'prior', $at);
    verify($receiver->receive($body, $prior)->payload === ['order' => 'B'], 'Old delivery failed.');
    $overlap = foundationWebhookApplication($backend, $root, [$new, $old], 'orders')->make(\Infocyph\TalkingBytes\Webhook\WebhookReceiver::class);
    rejectFoundationWebhook(static fn() => $overlap->receive($body, $prior), 'already been processed');
    $oldOverlap = foundationWebhookHeaders($old, $body, 'old-overlap', $at);
    $newOverlap = foundationWebhookHeaders($new, $body, 'new-overlap', $at);
    verify($overlap->receive($body, $oldOverlap)->deliveryId === 'old-overlap' && $overlap->receive($body, $newOverlap)->deliveryId === 'new-overlap', 'Configured secret overlap failed.');
    $cutover = foundationWebhookApplication($backend, $root, [$new], 'orders')->make(\Infocyph\TalkingBytes\Webhook\WebhookReceiver::class);
    $oldOnly = foundationWebhookHeaders($old, $body, 'new-delivery', $at);
    rejectFoundationWebhook(static fn() => $cutover->receive($body, $oldOnly), 'Webhook verification failed:');
    $newOnly = foundationWebhookHeaders($new, $body, 'new-delivery', $at);
    verify($cutover->receive($body, $newOnly)->deliveryId === 'new-delivery', 'Invalid retired secret consumed valid delivery.');
    rejectFoundationWebhook(static fn() => $cutover->receive($body, $newOverlap), 'already been processed');
    $foreign = foundationWebhookApplication($backend, $root, [$new], 'foreign')->make(\Infocyph\TalkingBytes\Webhook\WebhookReceiver::class);
    verify($foreign->receive($body, $newOverlap)->deliveryId === 'new-overlap', 'Replay namespace crossed configured profile.');
    $expired = foundationWebhookHeaders($new, $body, 'expired', $at - 301);
    rejectFoundationWebhook(static fn() => $cutover->receive($body, $expired), 'Webhook verification failed:');
    echo "Foundation configured $backend webhook secret rotation, retained replay and namespace isolation passed\n";
}

function foundationValidatorProfileContracts(): void
{
    require_once __DIR__ . '/bootstrap.php';
    putenv('AUTH_TOKEN_SECRET=' . bin2hex(random_bytes(32)));
    $config = \Experiment\configuration();
    $config['app']['capabilities'] = ['database', 'validation'];
    $config['validation']['schemas']['lab.stock'] = [
        'sku' => 'required|integer|exists:lab_provider_stock,id',
        'quantity' => 'required|integer|min:1|max:5',
    ];
    $config['validation']['defaults'] = ['fail_fast' => false, 'strip_unknown' => true, 'casts' => ['quantity' => 'integer']];
    $config['validation']['overrides']['lab.stock'] = ['messages' => ['quantity.min' => 'Quantity below minimum.']];
    $app = \Infocyph\Foundation\Foundation::worker($config)->boot();
    $app->execution()->run(static function () use ($app): void {
        $db = $app->make(\Infocyph\Foundation\Database\DBLayerFactory::class)->connection();
        $db->statement('CREATE TABLE lab_provider_stock (id INTEGER PRIMARY KEY)');
        $db->table('lab_provider_stock')->insert(['id' => 1]);
        $factory = $app->make(\Infocyph\Foundation\Validation\ValidatorFactory::class);
        foreach ([$factory->make('lab.stock'), $factory->compile('lab.stock')] as $validator) {
            $result = $validator->validate(['sku' => 1, 'quantity' => '2', 'foreign' => 'discard']);
            verify($result->passes() && $result->typed()['quantity'] === 2 && !array_key_exists('foreign', $result->typed()), 'Configured cast or strip policy lost.');
            $invalid = $validator->validate(['sku' => 999, 'quantity' => 0]);
            verify($invalid->hasError('sku') && $invalid->first('quantity') === 'Quantity below minimum.', 'Configured database rule or message failed.');
            verify($validator->validate(['sku' => 1, 'quantity' => 3])->passes(), 'Previous failure contaminated reused profile.');
        }
        $db->table('lab_provider_stock')->delete();
        verify($factory->compile('lab.stock')->validate(['sku' => 1, 'quantity' => 1])->hasError('sku'), 'Database provider returned stale existence.');
    });
    echo "Foundation named validation defaults, overrides, compiled parity and live database dependency passed\n";
}
