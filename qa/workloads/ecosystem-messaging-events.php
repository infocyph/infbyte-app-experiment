<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Envelope\RouteStamp;
use Infocyph\Omnibus\Event\EventDispatcher;
use Infocyph\Omnibus\Event\ListenerMap;
use Infocyph\Omnibus\Event\QueuedListener;
use Infocyph\Omnibus\Event\QueuedListenerHandler;
use Infocyph\Omnibus\Event\QueuedListenerNotConfigured;
use Infocyph\Omnibus\Event\QueuedListenerResolver;
use Infocyph\Omnibus\Event\ShouldQueue;
use Infocyph\Omnibus\Handler\HandlerContext;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Handler\HandlerMiddleware;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Routing\AmbiguousRoute;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Infocyph\Omnibus\Transport\UnsupportedDelay;
use Psr\EventDispatcher\StoppableEventInterface;

final class LabMiddlewareTrace
{
    /** @var list<string> */
    public array $events = [];
}

final readonly class LabJobMiddleware implements HandlerMiddleware
{
    public function __construct(private LabMiddlewareTrace $trace, private string $name) {}

    public function process(object $message, Envelope $envelope, HandlerContext $context, callable $next): mixed
    {
        verify($message instanceof LabJob && $context->attempt === 1, 'Middleware received invalid context.');
        $this->trace->events[] = $this->name . ':enter:' . $context->queue . ':' . ($context->asynchronous ? 'async' : 'sync');
        try {
            if ($this->name === 'outer' && $message->id === 'short') {
                return 'short-circuit';
            }
            return $next($message, $envelope, $context);
        } finally {
            $this->trace->events[] = $this->name . ':leave';
        }
    }
}

interface LabEventTopic {}
interface LabEventAlternate {}

class LabEventParent {}

final class LabOrderEvent extends LabEventParent implements LabEventTopic, LabEventAlternate, StoppableEventInterface
{
    public bool $stopped = false;

    public function __construct(public readonly string $id, public readonly int $amount) {}

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}

final class LabQueuedReceipt implements ShouldQueue {}

function omnibusMiddlewareWorkload(): void
{
    [$db, $serializer, , $clock] = messagingSetup();
    $trace = new LabMiddlewareTrace();
    $invoker = new HandlerInvoker(new HandlerMap([
        LabJob::class => static function (LabJob $job, Envelope $envelope, ?string $queue = null) use ($db, $trace): string {
            $trace->events[] = 'handler:' . ($queue ?? 'async');
            verify($envelope->message === $job, 'Handler envelope identity changed.');
            if ($job->id === 'failure') {
                throw new DomainException('Intentional handler failure.');
            }
            $db->table('lab_job_effects')->insert(['id' => $job->id, 'step' => $job->step]);
            return 'handled-' . $job->step;
        },
    ]), [new LabJobMiddleware($trace, 'outer'), new LabJobMiddleware($trace, 'inner')]);
    $sync = new SyncTransport($invoker);
    $async = new DBLayerTransport($db, $serializer, $clock);
    $bus = new MessageBus(new RouteMap([LabJob::class => new Route('sync', 'checkout')]), new TransportRegistry(['sync' => $sync]));
    $input = new Envelope(new LabJob('sync', 42), [new MessageIdStamp('fixed-id'), new RouteStamp('old', 'old'), new HandledStamp('stale')]);
    $sent = $bus->dispatch($input);
    verify($sent->last(HandledStamp::class)->result === 'handled-42' && count($sent->all(HandledStamp::class)) === 1, 'Synchronous handled result mismatch.');
    verify($sent->last(MessageIdStamp::class)->id === 'fixed-id' && $sent->last(RouteStamp::class)->queue === 'checkout', 'Dispatch identity or route replacement mismatch.');
    verify($input->last(HandledStamp::class)->result === 'stale', 'Dispatch mutated caller envelope.');
    verify($trace->events === ['outer:enter:checkout:sync', 'inner:enter:checkout:sync', 'handler:checkout', 'inner:leave', 'outer:leave'], 'Middleware nesting order mismatch.');
    $trace->events = [];
    verify($bus->dispatch(new LabJob('short', 0))->last(HandledStamp::class)->result === 'short-circuit' && $trace->events === ['outer:enter:checkout:sync', 'outer:leave'], 'Short circuit dispatched downstream.');
    $trace->events = [];
    expectFailure(static fn() => $bus->dispatch(new LabJob('failure', 0)), DomainException::class, 'Synchronous handler failure swallowed.');
    verify($trace->events === ['outer:enter:checkout:sync', 'inner:enter:checkout:sync', 'handler:checkout', 'inner:leave', 'outer:leave'], 'Exception failed to unwind middleware.');
    expectFailure(static fn() => $bus->dispatch(new Envelope(new LabJob('delay', 0), [new DelayStamp(1.0)])), UnsupportedDelay::class, 'Sync delay accepted.');
    $trace->events = [];
    $queueBus = new MessageBus(new RouteMap([LabJob::class => new Route('sql', 'orders')]), new TransportRegistry(['sql' => $async]));
    $queueBus->dispatch(new LabJob('async', 7));
    verify($trace->events === [] && $async->size('orders') === 1, 'Async handler ran during enqueue.');
    $failures = new DBLayerFailureStore($db, $serializer);
    $consumer = new Consumer($async, $invoker, new ExponentialRetryStrategy(maximumAttempts: 1), $failures, $clock);
    $result = $consumer->run('orders', 1);
    verify($result->succeeded === 1 && $trace->events === ['outer:enter:orders:async', 'inner:enter:orders:async', 'handler:async', 'inner:leave', 'outer:leave'], 'Async middleware context/order mismatch.');
    verify($db->table('lab_job_effects')->count() === 2 && $async->size('orders') === 0 && count($failures->all(100)) === 0, 'Middleware effect reconciliation failed.');

    $event = new LabOrderEvent('route', 1);
    $exact = new Route('sync', 'exact');
    $parent = new Route('sync', 'parent');
    $interface = new Route('sync', 'interface');
    $other = new Route('sync', 'other');
    verify(new RouteMap([LabOrderEvent::class => $exact, LabEventParent::class => $parent, LabEventTopic::class => $interface])->for($event) === $exact, 'Exact route precedence failed.');
    verify(new RouteMap([LabEventParent::class => $parent, LabEventTopic::class => $interface])->for($event) === $parent, 'Parent route precedence failed.');
    $same = new RouteMap([LabEventTopic::class => $interface, LabEventAlternate::class => $interface]);
    verify($same->for($event) === $interface && $same->for($event) === $interface, 'Compatible interface route changed.');
    expectFailure(static fn() => new RouteMap([LabEventTopic::class => $interface, LabEventAlternate::class => $other])->for($event), AmbiguousRoute::class, 'Conflicting interface routes accepted.');
    echo "Sync/SQL middleware order, short circuit, unwinding, context and route precedence passed\n";
}

function omnibusEventsWorkload(): void
{
    [$db, , , $clock] = messagingSetup();
    $codec = new CallbackMessageCodec('lab.queued-receipt.v1', QueuedListener::class,
        static function (QueuedListener $message): array {
            verify($message->event instanceof LabOrderEvent, 'Unexpected queued event type.');
            return ['listener' => $message->listener, 'id' => $message->event->id, 'amount' => $message->event->amount];
        },
        static fn(array $payload): QueuedListener => new QueuedListener((string) $payload['listener'], new LabOrderEvent((string) $payload['id'], (int) $payload['amount'])),
    );
    $serializer = new JsonEnvelopeSerializer(new MessageCodecRegistry([$codec]), new StampCodecRegistry(CoreStampCodecs::all()));
    $queue = new DBLayerTransport($db, $serializer, $clock);
    $bus = new MessageBus(new RouteMap([QueuedListener::class => new Route('sql', 'listeners')]), new TransportRegistry(['sql' => $queue]));
    $trace = [];
    $marker = new LabQueuedReceipt();
    $dispatcher = new EventDispatcher(new ListenerMap([
        LabOrderEvent::class => [
            static function (LabOrderEvent $event) use (&$trace): void {
                $trace[] = 'exact:' . $event->id;
                $event->stopped = $event->id === 'stopped';
            },
            $marker,
        ],
        LabEventParent::class => [static function (LabOrderEvent $event) use (&$trace): void { $trace[] = 'parent:' . $event->id; }],
        LabEventTopic::class => [static function (LabOrderEvent $event) use (&$trace): void { $trace[] = 'interface:' . $event->id; }],
    ]), $bus);
    $stopped = new LabOrderEvent('stopped', 0);
    verify($dispatcher->dispatch($stopped) === $stopped && $trace === ['exact:stopped'] && $queue->size('listeners') === 0, 'Stopped event dispatched additional listeners.');
    $trace = [];
    $event = new LabOrderEvent('receipt', 42);
    verify($dispatcher->dispatch($event) === $event && $trace === ['exact:receipt', 'parent:receipt', 'interface:receipt'], 'Listener order or identity mismatch.');
    verify($queue->size('listeners') === 1 && $db->table('lab_job_effects')->count() === 0, 'Queued listener executed synchronously.');
    expectFailure(static fn() => new EventDispatcher(new ListenerMap([LabOrderEvent::class => [$marker]]))->dispatch($event), QueuedListenerNotConfigured::class, 'Missing queued bus accepted.');
    expectFailure(static fn() => new QueuedListenerResolver([])->resolve(LabQueuedReceipt::class), QueuedListenerNotConfigured::class, 'Unknown listener resolved.');
    // Rebuild transport and resolver using a fresh DB connection rather than retaining producer state.
    [$replacement, , , $newClock] = messagingSetup(false);
    $resolver = new QueuedListenerResolver([LabQueuedReceipt::class => static function (LabOrderEvent $received) use ($replacement): void {
        $replacement->table('lab_job_effects')->insert(['id' => $received->id, 'step' => $received->amount]);
    }]);
    $transport = new DBLayerTransport($replacement, $serializer, $newClock);
    $failures = new DBLayerFailureStore($replacement, $serializer);
    $consumer = new Consumer($transport, new HandlerInvoker(new HandlerMap([QueuedListener::class => new QueuedListenerHandler($resolver)])), new ExponentialRetryStrategy(maximumAttempts: 1), $failures, $newClock);
    verify($consumer->run('listeners', 1)->succeeded === 1, 'Replacement queued listener failed.');
    verify($consumer->run('listeners', 1)->received === 0 && $replacement->table('lab_job_effects')->count() === 1 && (int) $replacement->table('lab_job_effects')->where('id', '=', 'receipt')->value('step') === 42, 'Queued listener effects diverged.');
    verify(count($failures->all(100)) === 0, 'Unexpected queued listener failures.');
    echo "PSR listener order, propagation stop, explicit queued codec and durable replacement listener passed\n";
}
