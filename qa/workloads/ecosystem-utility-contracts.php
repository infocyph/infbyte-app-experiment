<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\UID\Exceptions\FileLockException;
use Infocyph\UID\Exceptions\SequenceTimestampException;
use Infocyph\UID\Sequence\CallbackSequenceProvider;
use Infocyph\UID\Sequence\InMemorySequenceProvider;
use Infocyph\UID\Sequence\PsrSimpleCacheSequenceProvider;

function uidProviderBoundariesWorkload(): void
{
    $timestamp = 1800000000;
    $memory = new InMemorySequenceProvider();
    for ($i = 1; $i <= 1024; ++$i) {
        verify($memory->next('domain', $i, $timestamp) === 1, 'In-memory domain collision.');
    }
    expectFailure(static fn() => $memory->next('domain', 1025, $timestamp), FileLockException::class, 'In-memory domain limit bypassed.');
    verify($memory->next('domain', 1, $timestamp) === 2, 'Existing domain lost at capacity.');
    expectFailure(static fn() => $memory->next('domain', 1, $timestamp - 1), SequenceTimestampException::class, 'Regressed clock accepted.');
    verify($memory->next('domain', 1, $timestamp + 1) === 1, 'Timestamp advance did not reset sequence.');

    $options = new CacheOptions(failOpen: false);
    $cache = Cache::redis('uid-provider-boundaries', options: $options);
    $provider = new PsrSimpleCacheSequenceProvider($cache);
    verify($provider->next('order', 1, $timestamp) === 1, 'Shared sequence initialization failed.');
    $replacement = new PsrSimpleCacheSequenceProvider($cache);
    verify($replacement->next('order', 1, $timestamp) === 2 && $provider->next('order', 1, $timestamp) === 3, 'Replacement reused an allocation.');
    expectFailure(static fn() => $provider->next('order', 1, $timestamp - 1), SequenceTimestampException::class, 'Shared sequence accepted clock regression.');
    verify($cache->set('uid.seq.order.1', ['timestamp' => $timestamp, 'sequence' => 2]), 'Regressed fixture write failed.');
    expectFailure(static fn() => $provider->next('order', 1, $timestamp), FileLockException::class, 'Regressed durable state accepted.');
    verify($cache->delete('uid.seq.order.1'), 'State loss injection failed.');
    expectFailure(static fn() => $provider->next('order', 1, $timestamp), FileLockException::class, 'Lost durable state accepted.');
    verify($cache->set('uid.seq.exhausted.1', ['timestamp' => $timestamp, 'sequence' => PHP_INT_MAX]), 'Exhausted fixture write failed.');
    expectFailure(static fn() => $replacement->next('exhausted', 1, $timestamp), FileLockException::class, 'Exhausted sequence wrapped.');
    verify($replacement->next('exhausted', 1, $timestamp + 1) === 1, 'Timestamp advance after exhaustion failed.');
    verify($cache->set('uid.seq.malformed.1', ['timestamp' => $timestamp, 'sequence' => 0]), 'Malformed fixture write failed.');
    expectFailure(static fn() => $replacement->next('malformed', 1, $timestamp), FileLockException::class, 'Malformed sequence accepted.');
    $callback = new CallbackSequenceProvider(static fn(string $type, int $machine, int $time): int => $type === 'order' && $machine === 42 && $time === $timestamp ? 7 : 0);
    verify($callback->next('order', 42, $timestamp) === 7, 'Callback boundary arguments changed.');
    expectFailure(static fn() => $callback->next('order', 43, $timestamp), FileLockException::class, 'Nonpositive callback result accepted.');
    echo "Sequence provider capacity, clock, durable state loss/regression, exhaustion and callback boundaries passed\n";
}

interface LabContextualChannel
{
    public function name(): string;
}

final class LabContextualPrimary implements LabContextualChannel
{
    public function name(): string
    {
        return 'primary';
    }
}

final class LabContextualSecondary implements LabContextualChannel
{
    public function name(): string
    {
        return 'secondary';
    }
}

final readonly class LabContextualCheckout
{
    public function __construct(public LabContextualChannel $channel, public int $limit) {}
}

final readonly class LabContextualReport
{
    public function __construct(public LabContextualChannel $channel, public int $limit) {}
}

function diContextualWorkload(): void
{
    $path = evidenceDirectory() . '/contextual-container.php';
    $builder = ContainerBuilder::create('contextual-contracts')
        ->autowire(LabContextualChannel::class, LabContextualPrimary::class)
        ->autowire(LabContextualPrimary::class, LabContextualPrimary::class, tags: ['channels'])
        ->autowire(LabContextualSecondary::class, LabContextualSecondary::class, tags: ['channels'])
        ->autowire(LabContextualCheckout::class, LabContextualCheckout::class)
        ->autowire(LabContextualReport::class, LabContextualReport::class);
    $builder->when(LabContextualCheckout::class)->needs(LabContextualChannel::class)->giveReference(LabContextualSecondary::class);
    $builder->when(LabContextualCheckout::class)->needs('$limit')->giveValue(0);
    $builder->when(LabContextualReport::class)->needs(LabContextualChannel::class)->giveClass(LabContextualPrimary::class);
    $builder->when(LabContextualReport::class)->needs('$limit')->giveValue(100);
    $builder->compile($path, strict: true);
    foreach ([$builder->build(), $builder->production($path)] as $runtime) {
        $checkout = $runtime->get(LabContextualCheckout::class);
        $report = $runtime->get(LabContextualReport::class);
        verify($checkout->channel === $runtime->get(LabContextualSecondary::class) && $checkout->limit === 0, 'Contextual reference/value mismatch.');
        verify($report->channel->name() === 'primary' && $report->limit === 100, 'Contextual class/value mismatch.');
        verify($runtime->get(LabContextualChannel::class)->name() === 'primary', 'Contextual override polluted default.');
        $names = [];
        foreach ($runtime->tagged('channels') as $channel) {
            $names[] = $channel->name();
        }
        sort($names);
        verify($names === ['primary', 'secondary'], 'Tagged resolution mismatch.');
        verify(iterator_to_array($runtime->tagged('unknown')) === [], 'Unknown tag returned services.');
    }
    $invalid = ContainerBuilder::create('invalid-contextual');
    expectFailure(static fn() => $invalid->when(LabContextualCheckout::class)->giveValue(1), ContainerException::class, 'Contextual selection omitted.');
    expectFailure(static fn() => $invalid->when(LabContextualCheckout::class)->needs('')->giveValue(1), ContainerException::class, 'Empty dependency accepted.');
    echo "Dynamic/compiled contextual class/reference/value selection, tags and invalid configuration passed\n";
}
