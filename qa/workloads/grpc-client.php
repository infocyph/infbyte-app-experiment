<?php

declare(strict_types=1);

use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\Foundation\Foundation;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcResult;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;

$root = getenv('PROJECT');
require $root . '/vendor/autoload.php';
require __DIR__ . '/helpers.php';

use function Experiment\ensure;

$port = filter_var(getenv('GRPC_TEST_PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
ensure(is_int($port), 'Invalid loopback gRPC port.');
$app = Foundation::worker([
    'app' => ['base_path' => $root, 'load_env' => false, 'capabilities' => ['communication']],
    '_config_cache' => false,
    'communication' => ['grpc' => ['default_profile' => 'native', 'profiles' => ['native' => ['retry' => ['enabled' => false]]]]],
])->boot();
$channel = new Grpc\Channel('127.0.0.1:' . $port, ['credentials' => Grpc\ChannelCredentials::createInsecure(), 'force_new' => true]);
$invoker = new readonly class ($channel) implements NativeGrpcInvoker {
    public function __construct(private Grpc\Channel $channel) {}

    public function invoke(string $method, mixed $message, GrpcMetadata $headers, ?float $deadlineSeconds = null): NativeGrpcResult
    {
        ensure(is_string($message), 'Expected binary gRPC fixture payload.');
        $deadline = Grpc\Timeval::now()->add(new Grpc\Timeval((int) (($deadlineSeconds ?? 3.0) * 1_000_000)));
        $call = new Grpc\Call($this->channel, $method, $deadline);
        $call->startBatch([
            Grpc\OP_SEND_INITIAL_METADATA => [],
            Grpc\OP_SEND_MESSAGE => ['message' => $message],
            Grpc\OP_SEND_CLOSE_FROM_CLIENT => true,
        ]);
        $event = $call->startBatch([
            Grpc\OP_RECV_INITIAL_METADATA => true,
            Grpc\OP_RECV_MESSAGE => true,
            Grpc\OP_RECV_STATUS_ON_CLIENT => true,
        ]);

        return new NativeGrpcResult(statusCode: $event->status->code, message: $event->message);
    }
};
try {
    for ($i = 0; $i < (int) $argv[2]; ++$i) {
        $id = $argv[1] . '-' . $i;
        $start = hrtime(true);
        $app->execution()->run(static function () use ($app, $invoker, $id): void {
            $client = $app->make(CommunicationProfiles::class)->grpcNative($invoker);
            $payload = $id . ':' . str_repeat('x', 1024);
            $result = $client->send(new GrpcRequest('/foundation.Integration/Unary', $payload, deadlineSeconds: 3.0));
            ensure($result->successful && $result->response instanceof GrpcResponse && $result->response->message === 'echo:' . $payload, 'Native gRPC response mismatch.');
            $missing = $client->send(new GrpcRequest('/foundation.Integration/Missing', $payload, deadlineSeconds: 3.0));
            ensure(!$missing->successful && $missing->statusCode === 12, 'Unknown method did not return UNIMPLEMENTED.');
        });
        echo json_encode(['id' => $id, 'milliseconds' => (hrtime(true) - $start) / 1e6], JSON_THROW_ON_ERROR), PHP_EOL;
    }
} finally {
    $channel->close();
}
