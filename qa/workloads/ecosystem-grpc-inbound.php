<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundExchange;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundSource;

final class LabInboundExchange implements GrpcInboundExchange
{
    public ?GrpcInboundResponse $response = null;

    public function __construct(private readonly GrpcInboundRequest $input) {}

    public function request(): GrpcInboundRequest
    {
        return $this->input;
    }

    public function complete(GrpcInboundResponse $response): void
    {
        verify($this->response === null, 'Exchange completed twice.');
        $this->response = $response;
    }
}

final class LabInboundSource implements GrpcInboundSource
{
    public int $accepts = 0;

    public function __construct(private ?GrpcInboundExchange $exchange, private readonly ?Closure $onAccept = null) {}

    public function accept(?CancellationSignal $cancellation = null): ?GrpcInboundExchange
    {
        ++$this->accepts;
        if ($this->onAccept !== null) {
            ($this->onAccept)();
        }
        $next = $this->exchange;
        $this->exchange = null;

        return $next;
    }
}

final class LabInboundEvents implements EventDispatcher
{
    public array $events = [];

    public function dispatch(string $event, array $payload = []): void
    {
        $this->events[] = [$event, $payload];
    }
}

function grpcInboundWorkload(): void
{
    $pdo = new PDO('pgsql:host=127.0.0.1;dbname=experiment', 'experiment', 'experiment', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE lab_grpc_orders (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)');
    $events = new LabInboundEvents();
    $base = new GrpcInboundDispatcher(events: $events);
    $handlerCalls = 0;
    $method = '/orders.v1.OrderService/Create';
    $handler = static function (GrpcInboundRequest $request) use ($pdo, &$handlerCalls): GrpcInboundResponse {
        ++$handlerCalls;
        if ($request->headers->first('authorization') !== 'Bearer fixture-only') {
            return new GrpcInboundResponse(GrpcStatus::Unauthenticated);
        }
        if (($request->message['amount'] ?? 0) <= 0) {
            return new GrpcInboundResponse(GrpcStatus::InvalidArgument);
        }
        $id = $request->message['id'];
        $statement = $pdo->prepare('INSERT INTO lab_grpc_orders (id, amount) VALUES (?, ?) ON CONFLICT (id) DO NOTHING');
        $statement->execute([$id, $request->message['amount']]);
        return GrpcInboundResponse::ok(
            ['id' => $id, 'inserted' => $statement->rowCount() === 1],
            new GrpcMetadata(['x-request-id' => [$request->headers->first('x-request-id')]]),
            (new GrpcMetadata())->withBinaryValue('proof-bin', "\x00\xff"),
        );
    };
    $dispatcher = $base->withHandler($method, $handler)
        ->withHandler('/orders.v1.OrderService/Fail', static fn() => throw new RuntimeException('private-fixture-detail'));
    $headers = (new GrpcMetadata())->withValue('Authorization', 'Bearer fixture-only')->withValue('X-Request-Id', 'lab-42');
    verify($base->receive($method, [])->status === GrpcStatus::Unimplemented, 'Registration mutated original dispatcher.');
    $request = new GrpcInboundRequest($method, ['id' => 42, 'amount' => 250], $headers, 1.5);
    verify($request->deadlineMicros() === 1500000, 'Deadline conversion mismatch.');
    $exchange = new LabInboundExchange($request);
    $source = new LabInboundSource($exchange);
    verify($dispatcher->serveOne($source) && $exchange->response?->isOk() === true, 'Host exchange did not complete.');
    verify($exchange->response->message === ['id' => 42, 'inserted' => true]
        && $exchange->response->headers->first('x-request-id') === 'lab-42'
        && $exchange->response->trailers->firstBinary('proof-bin') === "\x00\xff", 'Inbound response metadata mismatch.');
    verify(!$dispatcher->serveOne($source) && $source->accepts === 2, 'Empty source did not stop.');
    $duplicate = $dispatcher->handle($request);
    verify($duplicate->message === ['id' => 42, 'inserted' => false], 'Application idempotency failed.');
    verify($dispatcher->receive($method, ['id' => 43, 'amount' => 250])->status === GrpcStatus::Unauthenticated, 'Unauthorized call accepted.');
    verify($dispatcher->receive($method, ['id' => 43, 'amount' => -1], $headers)->status === GrpcStatus::InvalidArgument, 'Invalid amount accepted.');
    $before = $handlerCalls;
    $cancelled = true;
    $signal = CancellationSignal::fromCallable(static function () use (&$cancelled): bool { return $cancelled; });
    $exchange = new LabInboundExchange($request);
    $source = new LabInboundSource($exchange);
    verify(!$dispatcher->serveOne($source, $signal) && $source->accepts === 0 && $exchange->response === null, 'Pre-accept cancellation failed.');
    $cancelled = false;
    $source = new LabInboundSource($exchange, static function () use (&$cancelled): void { $cancelled = true; });
    verify($dispatcher->serveOne($source, $signal) && $exchange->response?->status === GrpcStatus::Cancelled && $handlerCalls === $before, 'Accepted cancellation invoked handler.');
    $failure = $dispatcher->receive('/orders.v1.OrderService/Fail', ['secret' => 'private-fixture-detail']);
    verify($failure->status === GrpcStatus::Internal && $failure->message === 'Inbound gRPC handler failed.', 'Exception crossed response boundary.');
    verify((int) $pdo->query('SELECT COUNT(*) FROM lab_grpc_orders')->fetchColumn() === 1
        && (int) $pdo->query('SELECT SUM(amount) FROM lab_grpc_orders')->fetchColumn() === 250, 'Durable inbound effects diverged.');
    $names = array_column($events->events, 0);
    verify(in_array('grpc.inbound.start', $names, true) && in_array('grpc.inbound.finish', $names, true) && in_array('grpc.inbound.failed', $names, true), 'Inbound events missing.');
    $encoded = json_encode($events->events, JSON_THROW_ON_ERROR);
    verify(!str_contains($encoded, 'private-fixture-detail') && !str_contains($encoded, 'fixture-only'), 'Diagnostic secret leakage.');
    foreach ([
        static fn() => new GrpcInboundRequest('invalid', []),
        static fn() => new GrpcInboundRequest($method, [], deadlineSeconds: 0),
        static fn() => new GrpcMetadata(['x-invalid' => ["a\r\nb"]]),
        static fn() => new GrpcMetadata(['x-large' => [str_repeat('x', 8193)]]),
    ] as $invalid) {
        expectFailure($invalid, InvalidArgumentException::class, 'Invalid inbound metadata/method/deadline accepted.');
    }
    echo "Host-controlled unary inbound dispatch, metadata, cancellation, errors, events and durable idempotent effects passed\n";
}
