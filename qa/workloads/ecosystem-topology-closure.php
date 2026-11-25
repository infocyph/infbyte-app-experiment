<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\AMQP\AmqpTransport;
use Infocyph\OTP\GenericOtp;
use Infocyph\OTP\HOTP;
use Infocyph\OTP\MobileOTP;
use Infocyph\OTP\OCRA;
use Infocyph\OTP\TOTP;
use Infocyph\OTP\ValueObjects\VerificationWindow;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;

function otpClusterRejectOffline(callable $operation): void
{
    $admitted = false;
    try {
        $admitted = $operation();
    } catch (\Infocyph\CacheLayer\Exceptions\CacheBackendException | \RedisException | \RedisClusterException) {
        // Only the expected native/cache failure is a fail-closed outcome.
    }
    verify(!$admitted, 'Cluster without quorum or full coverage admitted a valid authentication proof.');
}

function otpClusterPromotionWorkload(bool $seed, string $factor, bool $offline = false): void
{
    $path = (string) getenv('LAB_OTP_ELECTION_STATE');
    $options = new CacheOptions(integrityKey: str_repeat('i', 32), failOpen: false);
    $seeds = explode(',', (string) getenv('LAB_CLUSTER_SEEDS'));
    // The Redis Cluster protocol is also exercised against actual Valkey nodes.
    $factory = static fn(): Cache => Cache::redisCluster('otp-cluster-promotion', $seeds, options: $options);
    $state = $seed
        ? ['secret' => TOTP::generateSecret(32), 'key' => bin2hex(random_bytes(32))]
        : json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $window = new VerificationWindow(0, 0);
    $at = 1_800_000_000;
    if ($factor === 'generic' && $offline) {
        otpClusterRejectOffline(static fn(): bool => (new GenericOtp($factory(), hex2bin($state['key']), ttlSeconds: 600, maxAttempts: 3))
            ->verify('pending', $state['pending']));
        otpClusterRejectOffline(static fn(): bool => (new GenericOtp($factory(), hex2bin($state['key']), ttlSeconds: 600, maxAttempts: 3))
            ->verify('consumed', $state['consumed']));
        echo "Cluster rejected valid pending and spent challenges during outage\n";
        return;
    }
    if ($factor === 'generic') {
        $generic = new GenericOtp($factory(), hex2bin($state['key']), ttlSeconds: 600, maxAttempts: 3);
        if ($seed) {
            $state['consumed'] = $generic->generate('consumed');
            $state['pending'] = $generic->generate('pending');
            $state['exhausted'] = $generic->generate('exhausted');
            verify($generic->verify('consumed', $state['consumed']), 'Cluster seed challenge rejected.');
            $wrong = ($state['exhausted'][0] === '0' ? '1' : '0') . substr($state['exhausted'], 1);
            for ($i = 0; $i < 3; ++$i) {
                verify(!$generic->verify('exhausted', $wrong), 'Wrong proof admitted.');
            }
        } else {
            verify(!$generic->verify('consumed', $state['consumed']) && !$generic->verify('exhausted', $state['exhausted']), 'Promotion resurrected a spent challenge.');
            $race = parallelRace(static fn(): int => (int) (new GenericOtp($factory(), hex2bin($state['key']), ttlSeconds: 600, maxAttempts: 3))
                ->verify('pending', $state['pending']), 'otp-cluster-challenge', 12);
            verify(array_sum($race) === 1 && !$generic->verify('pending', $state['pending']), 'Promoted challenge consumed more than once.');
        }
    } else {
        $hotp = new HOTP($state['secret']);
        $totp = new TOTP($state['secret']);
        $mobile = new MobileOTP(substr($state['key'], 0, 16), '1234');
        $counter = new OCRA('OCRA-1:HOTP-SHA256-8:C-QN08', hex2bin($state['key']));
        $challenge = new OCRA('OCRA-1:HOTP-SHA256-8:QN08', hex2bin($state['key']));
        $admit = static function (bool $next, string $owner = 'factor') use ($factor, $factory, $hotp, $totp, $mobile, $counter, $challenge, $window, $at): bool {
            $c = $next ? 43 : 42;
            $t = $at + ($next ? 30 : 0);
            $q = $next ? '87654321' : '12345678';
            $cache = $factory();
            return match ($factor) {
                'hotp' => $hotp->verifyWithResult($hotp->generate($c), counter: $c, cache: $cache, factorId: $owner)->matched,
                'totp' => $totp->verifyWithWindow($totp->generate($t), $t, $window, $cache, $owner)->matched,
                'mobile' => $mobile->verifyWithWindow($mobile->generate($t), $t, $window, cache: $cache, factorId: $owner)->matched,
                'ocra-counter' => $counter->verifyWithResult($counter->generate($q, counter: $c), $q, counter: $c, cache: $cache, factorId: $owner)->matched,
                'ocra-challenge' => $challenge->verifyWithResult($challenge->generate($q), $q, cache: $cache, factorId: $owner, replayTtl: 600)->matched,
                default => throw new InvalidArgumentException('Unknown factor.'),
            };
        };
        if ($offline) {
            otpClusterRejectOffline(static fn(): bool => $admit(true));
            otpClusterRejectOffline(static fn(): bool => $admit(false));
            echo "$factor valid next and spent proofs rejected during cluster outage\n";
            return;
        }
        if ($seed) {
            verify($admit(false) && !$admit(false), 'Initial cluster replay cursor failed.');
        } else {
            verify(!$admit(false), 'Promoted replica regressed replay state.');
            $race = parallelRace(static fn(): int => (int) $admit(true), 'otp-cluster-factor', 12);
            verify(array_sum($race) === 1 && !$admit(true), 'Promoted cluster factor replay race failed.');
            verify($admit(true, 'other-owner') && !$admit(true, 'other-owner'), 'Promoted cluster crossed owner identity.');
        }
    }
    if ($seed) {
        verify(file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'Private seed evidence unavailable.');
    }
    echo "$factor cluster promotion " . ($seed ? 'seed' : 'recovery') . " passed\n";
}

function amqpQuorumPhase(bool $seed): void
{
    [$db, $serializer] = messagingSetup($seed);
    $root = (string) getenv('LAB_QUORUM_ROOT');
    $port = $seed ? 15672 : (int) getenv('LAB_QUORUM_PORT');
    $connection = new AMQPStreamConnection('127.0.0.1', $port, 'experiment', 'fixture-only-password', connection_timeout: 5.0, read_write_timeout: 15.0);
    $channel = $connection->channel();
    $queue = 'quorum-contract';
    $transport = new AmqpTransport(new LabAmqpBackend($channel), $serializer);
    try {
        $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
            'x-queue-type' => 'quorum', 'x-quorum-initial-group-size' => 3,
        ]));
        $channel->confirm_select();
        $confirmed = 0;
        $channel->set_ack_handler(static function () use (&$confirmed): void { ++$confirmed; });
        $channel->set_nack_handler(static function (): never { throw new RuntimeException('Quorum negatively acknowledged publication.'); });
        if ($seed) {
            if (str_ends_with((string) getenv('SCENARIO'), '-publish')) {
                $channel->queue_declare('quorum-publication', false, true, false, false, false, new AMQPTable([
                    'x-queue-type' => 'quorum', 'x-quorum-initial-group-size' => 3,
                ]));
                $transport->send(new Envelope(new LabJob('quorum-publication-control', 0)), 'quorum-publication');
                $channel->wait_for_pending_acks_returns(15.0);
                verify($confirmed === 1, 'Publication control was not confirmed before fault.');
                $control = iterator_to_array($transport->receive('quorum-publication', 1));
                verify(count($control) === 1 && $control[0]->envelope()->message->id === 'quorum-publication-control',
                    'Publication queue control unavailable before fault.');
                $transport->acknowledge($control[0]);
                verify($transport->size('quorum-publication') === 0, 'Publication control did not settle.');
                $confirmed = 0;
            }
            foreach (range(1, 12) as $id) {
                $transport->send(new Envelope(new LabJob('quorum-' . $id, $id)), $queue);
            }
            $channel->wait_for_pending_acks_returns(15.0);
            verify($confirmed === 12, 'Quorum did not confirm every seed publication.');
            $delivery = iterator_to_array($transport->receive($queue, 1))[0];
            verify($delivery->envelope()->message->id === 'quorum-1', 'Initial quorum reservation mismatch.');
            brokerRecordEffect($db, $delivery->envelope()->message);
            verify(file_put_contents($root . '/reserved-id', $delivery->messageId, LOCK_EX) !== false && touch($root . '/seeded'), 'Quorum reservation barrier unavailable.');
            cacheWaitMarker($root . '/member-stopped');
            // No ack is sent for this receipt. Connection destruction returns it
            // to the majority even when the original channel remains connected.
            return;
        }
        $transport->send(new Envelope(new LabJob('quorum-13', 13)), $queue);
        $channel->wait_for_pending_acks_returns(15.0);
        verify($confirmed === 1, 'Surviving majority rejected publication.');
        $seen = [];
        $deadline = hrtime(true) + 30_000_000_000;
        while (count($seen) < 13 && hrtime(true) < $deadline) {
            foreach ($transport->receive($queue, 13 - count($seen)) as $delivery) {
                $job = $delivery->envelope()->message;
                verify(!isset($seen[$job->id]), 'Duplicate recovery delivery.');
                if ($job->id === 'quorum-1') {
                    verify($delivery->messageId === file_get_contents($root . '/reserved-id') && $delivery->attempt >= 2, 'Unsettled quorum delivery lost identity.');
                }
                brokerRecordEffect($db, $job);
                $seen[$job->id] = $job->step;
                $transport->acknowledge($delivery);
            }
            usleep(50_000);
        }
        $channel->queue_declare($queue, true);
        verify(count($seen) === 13 && $transport->size($queue) === 0 && $db->table('lab_job_effects')->count() === 13, 'Quorum recovery ledger mismatch.');
        foreach (range(1, 13) as $id) {
            verify(($seen['quorum-' . $id] ?? null) === $id && (int) $db->table('lab_job_effects')->find('quorum-' . $id)['step'] === $id, 'Recovered SQL receipt differs.');
        }
        echo "Three-member quorum one-node loss and durable SQL reconciliation passed\n";
    } finally {
        try {
            $connection->close();
        } catch (\PhpAmqpLib\Exception\AMQPExceptionInterface $failure) {
            if (!$seed || !is_file($root . '/member-stopped')) {
                throw $failure;
            }
        }
    }
}

function amqpQuorumMinority(string $operation): void
{
    verify(in_array($operation, ['publish', 'receive'], true), 'Unknown minority operation.');
    [$db, $serializer] = messagingSetup(false);
    verify($db->table('lab_job_effects')->count() === 1, 'Seed effect unavailable before minority operation.');
    $connection = new AMQPStreamConnection('127.0.0.1', (int) getenv('LAB_QUORUM_PORT'), 'experiment', 'fixture-only-password',
        connection_timeout: 5.0, read_write_timeout: 8.0);
    $channel = $connection->channel();
    $transport = new AmqpTransport(new LabAmqpBackend($channel), $serializer);
    $confirmed = 0;
    $rejected = 0;
    $received = 0;
    $failureType = null;
    if ($operation === 'publish') {
        $channel->confirm_select();
        $channel->set_ack_handler(static function () use (&$confirmed): void { ++$confirmed; });
        $channel->set_nack_handler(static function () use (&$rejected): void { ++$rejected; });
    }
    try {
        try {
            if ($operation === 'publish') {
                $transport->send(new Envelope(new LabJob('quorum-14', 14)), 'quorum-publication');
                $channel->wait_for_pending_acks_returns(5.0);
            } else {
                $received = count(iterator_to_array($transport->receive('quorum-contract', 1)));
            }
        } catch (\PhpAmqpLib\Exception\AMQPExceptionInterface $failure) {
            $failureType = $failure::class;
        }
        verify($confirmed === 0 && $received === 0, 'A minority broker confirmed publication or delivered work.');
        if ($operation === 'publish') {
            verify($rejected > 0 || $failureType !== null, 'Unconfirmed publication did not report a bounded failure.');
        }
        verify($db->table('lab_job_effects')->count() === 1, 'Minority operation changed the durable ledger.');
        echo json_encode(['operation' => $operation, 'confirmed' => $confirmed, 'nacks' => $rejected,
            'received' => $received, 'failure' => $failureType], JSON_THROW_ON_ERROR), PHP_EOL;
    } finally {
        try {
            $connection->close();
        } catch (\PhpAmqpLib\Exception\AMQPExceptionInterface) {
            // Closing the minority connection cannot establish settlement.
        }
    }
}

function amqpQuorumMinorityRetry(): void
{
    [$db, $serializer] = messagingSetup(false);
    verify($db->table('lab_job_effects')->count() === 13, 'Original quorum recovery incomplete.');
    $connection = new AMQPStreamConnection('127.0.0.1', (int) getenv('LAB_QUORUM_PORT'), 'experiment', 'fixture-only-password',
        connection_timeout: 5.0, read_write_timeout: 15.0);
    try {
        $channel = $connection->channel();
        $queue = 'quorum-publication';
        $transport = new AmqpTransport(new LabAmqpBackend($channel), $serializer);
        $channel->confirm_select();
        $confirmed = 0;
        $channel->set_ack_handler(static function () use (&$confirmed): void { ++$confirmed; });
        $channel->set_nack_handler(static function (): never { throw new RuntimeException('Restored quorum rejected retry.'); });
        $transport->send(new Envelope(new LabJob('quorum-14', 14)), $queue);
        $channel->wait_for_pending_acks_returns(15.0);
        verify($confirmed === 1, 'Restored majority did not confirm retry.');
        $seen = 0;
        foreach ($transport->receive($queue, 3) as $delivery) {
            $job = $delivery->envelope()->message;
            verify($job->id === 'quorum-14' && $job->step === 14, 'Unexpected publication after quorum recovery.');
            brokerRecordEffect($db, $job);
            $transport->acknowledge($delivery);
            ++$seen;
        }
        $channel->queue_declare($queue, true);
        verify($seen >= 1 && $seen <= 2 && $transport->size($queue) === 0, 'Ambiguous publication could not be reconciled.');
        verify($db->table('lab_job_effects')->count() === 14
            && (int) $db->table('lab_job_effects')->find('quorum-14')['step'] === 14, 'Retry duplicated or lost the durable effect.');
        echo json_encode(['confirmed_retry' => $confirmed, 'observed_deliveries' => $seen, 'durable_effects' => 14],
            JSON_THROW_ON_ERROR), PHP_EOL;
    } finally {
        $connection->close();
    }
}
