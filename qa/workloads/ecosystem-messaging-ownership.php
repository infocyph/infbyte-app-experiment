<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureNotFound;
use Infocyph\Omnibus\Failure\FailureRetryClaimUnavailable;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Transport\InvalidReservation;
use Psr\Clock\ClockInterface;

final class LabMessagingClock implements ClockInterface
{
    private DateTimeImmutable $instant;

    public function __construct()
    {
        $this->instant = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }

    public function advance(int $seconds): void
    {
        $this->instant = $this->instant->modify('+' . $seconds . ' seconds');
    }
}

function omnibusReservationOwnership(): void
{
    [$db, $serializer] = messagingSetup();
    [$peer] = messagingSetup(false);
    $clock = new LabMessagingClock();
    $owner = new DBLayerTransport($db, $serializer, $clock);
    $replacement = new DBLayerTransport($peer, $serializer, $clock);
    $sent = $owner->send(new Envelope(new LabJob('delayed', 42), [new DelayStamp(5.0)]), 'orders');
    $message = $sent->last(MessageIdStamp::class);
    verify($message instanceof MessageIdStamp, 'Stable message identity unavailable.');
    $owner->send(new Envelope(new LabJob('other', 7)), 'other');
    verify($owner->size('orders') === 0 && iterator_to_array($replacement->receive('orders', 1)) === [], 'Delayed job appeared early.');
    $clock->advance(5);
    $rows = iterator_to_array($owner->receive('orders', 1, 10.0));
    verify(count($rows) === 1 && $rows[0]->messageId === $message->id && $rows[0]->attempt === 1, 'Initial reservation mismatch.');
    $first = $rows[0];
    verify(iterator_to_array($replacement->receive('orders', 1)) === [] && $owner->size('orders') === 0 && $replacement->size('other') === 1, 'Active reservation or queue isolation failed.');
    $clock->advance(10);
    $rows = iterator_to_array($replacement->receive('orders', 1, 10.0));
    verify(count($rows) === 1 && $rows[0]->receipt !== $first->receipt && $rows[0]->messageId === $first->messageId && $rows[0]->attempt === 2, 'Expired lease did not produce new ownership.');
    $second = $rows[0];
    foreach ([
        static fn() => $owner->acknowledge($first),
        static fn() => $owner->reject($first),
        static fn() => $owner->release($first),
    ] as $stale) {
        expectFailure($stale, InvalidReservation::class, 'Stale owner settled new reservation.');
    }
    $replacement->release($second, 3.0);
    verify(iterator_to_array($owner->receive('orders', 1)) === [], 'Released job bypassed delay.');
    $clock->advance(3);
    $rows = iterator_to_array($owner->receive('orders', 1, 10.0));
    verify(count($rows) === 1 && $rows[0]->attempt === 3 && $rows[0]->receipt !== $second->receipt && $rows[0]->envelope()->message->id === 'delayed', 'Release changed payload or attempt generation.');
    $third = $rows[0];
    expectFailure(static fn() => $replacement->acknowledge($second), InvalidReservation::class, 'Released receipt remained active.');
    $owner->acknowledge($third);
    expectFailure(static fn() => $owner->acknowledge($third), InvalidReservation::class, 'Repeated acknowledgement accepted.');
    $other = iterator_to_array($replacement->receive('other', 1));
    verify(count($other) === 1 && $other[0]->envelope()->message->id === 'other', 'Independent queue was mutated.');
    $replacement->reject($other[0]);
    verify($peer->table('omnibus_messages')->count() === 0, 'Terminal SQL rows remained.');
    echo "Delayed visibility, expired receipt replacement, stale settlement rejection, queue isolation and exact cleanup passed\n";
}

function omnibusFailureClaims(): void
{
    [$db, $serializer] = messagingSetup();
    [$peer] = messagingSetup(false);
    $clock = new LabMessagingClock();
    $owner = new DBLayerFailureStore($db, $serializer, clock: $clock);
    $replacement = new DBLayerFailureStore($peer, $serializer, clock: $clock);
    $time = $clock->now();
    $envelope = new Envelope(new LabJob('claim', 42), [new MessageIdStamp('claim')]);
    $owner->add(FailedMessage::decoded('claim', 'orders', $envelope, 2, $time, DomainException::class, 'current'));
    $replacement->add(FailedMessage::decoded('claim', 'orders', $envelope, 1, $time->modify('+1 second'), DomainException::class, 'stale'));
    verify($replacement->find('claim')?->attempt === 2 && $replacement->find('claim')?->reason === 'current', 'Stale failure replaced newer attempt.');
    $raw = "\0\xfffixture";
    $owner->add(FailedMessage::undecodable('raw', 'orders', $raw, 1, $time, RuntimeException::class, 'decode'));
    verify($replacement->find('raw')?->payload === $raw && $replacement->find('raw')?->envelope === null, 'Binary failure payload changed.');
    expectFailure(static fn() => $owner->claimRetry('missing'), FailureNotFound::class, 'Missing failure claimed.');
    $first = $owner->claimRetry('claim', 5.0);
    expectFailure(static fn() => $replacement->claimRetry('claim', 5.0), FailureRetryClaimUnavailable::class, 'Two active retry owners.');
    verify($replacement->prune($time->modify('+1 second')) === 1 && $owner->find('claim') !== null && $owner->find('raw') === null, 'Pruning removed active claim or retained eligible failure.');
    $clock->advance(5);
    $second = $replacement->claimRetry('claim', 5.0);
    verify($second->token !== $first->token && $second->failure->envelope?->message->id === 'claim', 'Claim recovery lost identity.');
    verify(!$owner->releaseRetry($first) && !$owner->markRetrySent($first) && !$owner->removeRetried($first), 'Expired token mutated replacement claim.');
    verify($replacement->releaseRetry($second), 'Current token could not release claim.');
    $third = $owner->claimRetry('claim', 5.0);
    verify($third->token !== $second->token && $owner->markRetrySent($third), 'Retry dispatch marker failed.');
    $clock->advance(10);
    expectFailure(static fn() => $replacement->claimRetry('claim'), FailureRetryClaimUnavailable::class, 'Already sent retry became reclaimable.');
    verify($replacement->prune($clock->now()) === 0 && !$replacement->removeRetried($second), 'Sent state lost to stale owner or pruning.');
    verify($owner->removeRetried($third) && $replacement->find('claim') === null && $peer->table('omnibus_failures')->count() === 0, 'Terminal failure cleanup mismatch.');
    echo "Failure version ordering, binary evidence, exclusive retry claim, expiry, stale tokens, sent-state protection and cleanup passed\n";
}

function omnibusFailureReplayContracts(): void
{
    [$db, $serializer] = messagingSetup();
    $clock = new LabMessagingClock();
    $store = new DBLayerFailureStore($db, $serializer, clock: $clock);
    $queue = new DBLayerTransport($db, $serializer, $clock);
    $manager = new \Infocyph\Omnibus\Failure\FailureManager($store);
    $envelope = new Envelope(new LabJob('replay', 42), [
        new MessageIdStamp('replay-id'), new \Infocyph\Omnibus\Envelope\AttemptStamp(3),
        new DelayStamp(90.0), new \Infocyph\Omnibus\Envelope\RouteStamp('old', 'old'),
        new \Infocyph\Omnibus\Envelope\UniqueStamp('old-key', 'old-token', 30.0),
    ]);
    $store->add(FailedMessage::decoded('replay-id', 'orders', $envelope, 3, $clock->now(), DomainException::class, 'fixture'));
    $sent = $manager->retry('replay-id', $queue, 'recovered');
    verify($sent->last(MessageIdStamp::class)->id === 'replay-id' && $sent->last(DelayStamp::class) === null && $sent->last(\Infocyph\Omnibus\Envelope\UniqueStamp::class) === null && $sent->last(\Infocyph\Omnibus\Envelope\AttemptStamp::class) === null && $sent->last(\Infocyph\Omnibus\Envelope\RouteStamp::class) === null, 'Retry retained delivery-local stamps.');
    [$peer] = messagingSetup(false);
    $other = new DBLayerTransport($peer, $serializer, $clock);
    $failures = new DBLayerFailureStore($peer, $serializer, clock: $clock);
    verify($failures->find('replay-id') === null && $other->size('orders') === 0 && $other->size('recovered') === 1, 'Retry removal or target queue differs.');
    expectFailure(static fn() => $manager->retry('replay-id', $queue), FailureNotFound::class, 'Settled failure retried twice.');
    $received = iterator_to_array($other->receive('recovered', 1));
    verify(count($received) === 1 && $received[0]->messageId === 'replay-id' && $received[0]->attempt === 1 && $received[0]->envelope()->message->step === 42, 'Retry identity or attempt reset differs.');
    $other->acknowledge($received[0]);
    verify($peer->table('omnibus_messages')->count() === 0 && $peer->table('omnibus_failures')->count() === 0, 'Retry left durable rows.');
    echo "SQL failure manager replay, delivery stamp reset and independent settlement passed\n";
}

function omnibusFailureRejectionContracts(): void
{
    [$db, $serializer] = messagingSetup();
    $clock = new LabMessagingClock();
    $store = new DBLayerFailureStore($db, $serializer, clock: $clock);
    $queue = new DBLayerTransport($db, $serializer, $clock);
    $manager = new \Infocyph\Omnibus\Failure\FailureManager($store);
    $raw = "\0\xffbad";
    $store->add(FailedMessage::undecodable('raw', 'orders', $raw, 1, $clock->now(), RuntimeException::class, 'raw'));
    $workflow = new Envelope(new LabJob('workflow', 1), [new MessageIdStamp('workflow'), new \Infocyph\Omnibus\Envelope\ChainStamp('chain-id', 'item-id', 0)]);
    $store->add(FailedMessage::decoded('workflow', 'orders', $workflow, 2, $clock->now(), DomainException::class, 'workflow'));
    expectFailure(static fn() => $manager->retry('raw', $queue), \Infocyph\Omnibus\Failure\UndecodableFailure::class, 'Undecodable failure dispatched.');
    expectFailure(static fn() => $manager->retry('workflow', $queue), \Infocyph\Omnibus\Failure\WorkflowFailureRequiresRecovery::class, 'Workflow failure dispatched as ordinary job.');
    [$peer] = messagingSetup(false);
    $replacement = new DBLayerFailureStore($peer, $serializer, clock: $clock);
    verify($replacement->find('raw')?->payload === $raw && $replacement->find('workflow')?->envelope?->last(\Infocyph\Omnibus\Envelope\ChainStamp::class)?->itemId === 'item-id', 'Rejected retry changed evidence.');
    foreach (['raw', 'workflow'] as $id) {
        $claim = $replacement->claimRetry($id, 5.0);
        verify($replacement->releaseRetry($claim), 'Validation failure leaked retry claim.');
    }
    verify($queue->size('orders') === 0 && $manager->forget('raw') && !$manager->forget('raw') && $manager->flush() === 1 && $peer->table('omnibus_failures')->count() === 0, 'Failure removal/flush reconciliation differs.');
    echo "SQL undecodable/workflow retry rejection, claim cleanup and explicit failure removal passed\n";
}

function omnibusTransactionContracts(): void
{
    [$db, $serializer] = messagingSetup();
    [$peer] = messagingSetup(false);
    $clock = new LabMessagingClock();
    $queue = new DBLayerTransport($db, $serializer, $clock);
    $other = new DBLayerTransport($peer, $serializer, $clock);
    try {
        $db->transaction(static function ($active) use ($queue): never {
            $active->table('lab_job_effects')->insert(['id' => 'rolled-back', 'step' => 1]);
            $queue->send(new Envelope(new LabJob('rolled-back', 1)), 'orders');
            throw new DomainException('fixture rollback');
        });
        throw new LogicException('Transaction did not roll back.');
    } catch (\Infocyph\DBLayer\Exceptions\TransactionException $failure) {
        verify($failure->getPrevious() instanceof DomainException, 'Unexpected rollback cause.');
    }
    verify($other->size('orders') === 0 && $peer->table('lab_job_effects')->count() === 0, 'Rollback leaked queue or business row.');
    $db->transaction(static function ($active) use ($queue): void {
        $active->table('lab_job_effects')->insert(['id' => 'committed', 'step' => 2]);
        $queue->send(new Envelope(new LabJob('committed', 2)), 'orders');
    });
    verify($other->size('orders') === 1 && $peer->table('lab_job_effects')->count() === 1, 'Commit did not publish both rows.');
    $reservation = iterator_to_array($other->receive('orders', 1))[0];
    verify($reservation->envelope()->message->id === 'committed', 'Rolled-back message was delivered.');
    $other->acknowledge($reservation);
    verify($peer->table('omnibus_messages')->count() === 0 && (int) $peer->table('lab_job_effects')->value('step') === 2, 'Terminal durable reconciliation differs.');
    echo "SQL transaction-bound enqueue, rollback and committed business/queue reconciliation passed\n";
}
