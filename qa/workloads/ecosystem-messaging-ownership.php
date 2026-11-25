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
