<?php

declare(strict_types=1);

use Google\Protobuf\GPBEmpty;
use Grpc\BaseStub;
use Grpc\ChannelCredentials;
use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\Foundation\Foundation;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;

require getenv('PROJECT') . '/vendor/autoload.php';
require __DIR__ . '/helpers.php';

use function Experiment\ensure;

final class LabStreamStub extends BaseStub
{
    public function Unary(GPBEmpty $message, array $metadata = [], array $options = []): object
    {
        return $this->_simpleRequest('/lab.Stream/Unary', $message, [GPBEmpty::class, 'decode'], $metadata, $options);
    }

    public function Server(GPBEmpty $message, array $metadata = [], array $options = []): object
    {
        return $this->_serverStreamRequest('/lab.Stream/Server', $message, [GPBEmpty::class, 'decode'], $metadata, $options);
    }

    public function Slow(GPBEmpty $message, array $metadata = [], array $options = []): object
    {
        return $this->_serverStreamRequest('/lab.Stream/Slow', $message, [GPBEmpty::class, 'decode'], $metadata, $options);
    }

    public function Client(array $metadata = [], array $options = []): object
    {
        return $this->_clientStreamRequest('/lab.Stream/Client', [GPBEmpty::class, 'decode'], $metadata, $options);
    }

    public function Bidi(array $metadata = [], array $options = []): object
    {
        return $this->_bidiRequest('/lab.Stream/Bidi', [GPBEmpty::class, 'decode'], $metadata, $options);
    }
}

$ca = file_get_contents((string) getenv('GRPC_LAB_CA'));
ensure(is_string($ca), 'Missing trust anchor.');
$target = 'localhost:' . (int) getenv('GRPC_TEST_PORT');
$stub = new LabStreamStub($target, ['credentials' => ChannelCredentials::createSsl($ca)]);
$app = Foundation::worker([
    'app' => ['base_path' => getenv('PROJECT'), 'load_env' => false, 'capabilities' => ['communication']],
    '_config_cache' => false,
    'communication' => ['grpc' => ['default_profile' => 'native', 'profiles' => ['native' => ['retry' => ['enabled' => false]]]]],
])->boot();
$messages = static function (): Generator {
    for ($i = 0; $i < 64; ++$i) {
        yield new GPBEmpty();
    }
};
try {
    for ($i = 0; $i < 20; ++$i) {
        $app->execution()->run(static function () use ($app, $stub, $messages): void {
            $client = $app->make(CommunicationProfiles::class)->grpcGeneratedStub($stub);
            $unary = $client->send(new GrpcRequest('Stream/Unary', new GPBEmpty(), deadlineSeconds: 3.0));
            ensure($unary->successful && $unary->response instanceof GrpcResponse && $unary->response->message instanceof GPBEmpty && $unary->response->trailers->first('x-trailer') === 'unary', 'TLS unary response mismatch.');
            $count = 0;
            $consume = static function (mixed $message) use (&$count): void {
                ensure($message instanceof GPBEmpty, 'Invalid stream payload.');
                ++$count;
            };
            $result = $client->serverStream(new GrpcRequest('Stream/Server', new GPBEmpty(), deadlineSeconds: 3.0), $consume);
            ensure($result->successful && $count === 64 && $result->response?->trailers->first('x-count') === '64', 'Server stream reconciliation failed.');
            $result = $client->clientStream('Stream/Client', $messages(), deadlineSeconds: 3.0);
            ensure($result->successful && $result->response?->message instanceof GPBEmpty && $result->response->trailers->first('x-count') === '64', 'Client stream reconciliation failed.');
            $count = 0;
            $result = $client->bidiStream('Stream/Bidi', $messages(), $consume, deadlineSeconds: 3.0);
            ensure($result->successful && $count === 64 && $result->response?->trailers->first('x-count') === '64', 'Bidi stream reconciliation failed.');
        });
    }
    $client = GrpcClient::usingGeneratedStub($stub);
    $deadline = $client->serverStream(new GrpcRequest('Stream/Slow', new GPBEmpty(), deadlineSeconds: 0.05), static function (): void {});
    ensure(!$deadline->successful && $deadline->statusCode === 4, 'Native deadline did not terminate call.');
    $cancelled = false;
    $count = 0;
    $signal = CancellationSignal::fromCallable(static function () use (&$cancelled): bool { return $cancelled; });
    $cancel = GrpcClient::usingGeneratedStub($stub, cancellation: $signal)->serverStream(
        new GrpcRequest('Stream/Server', new GPBEmpty(), deadlineSeconds: 3.0),
        static function () use (&$cancelled, &$count): void { ++$count; $cancelled = true; },
    );
    ensure(!$cancel->successful && $count === 1, 'Cancelled stream delivered more messages.');
    $untrusted = new LabStreamStub($target, ['credentials' => ChannelCredentials::createSsl()]);
    try {
        $bad = GrpcClient::usingGeneratedStub($untrusted)->send(new GrpcRequest('Stream/Unary', new GPBEmpty(), deadlineSeconds: 1.0));
        ensure(!$bad->successful, 'Untrusted TLS certificate accepted.');
    } finally {
        $untrusted->close();
    }
    $recovered = $client->send(new GrpcRequest('Stream/Unary', new GPBEmpty(), deadlineSeconds: 3.0));
    ensure($recovered->successful, 'Channel not reusable after deadline/cancellation.');
} finally {
    $stub->close();
}
echo "Worker {$argv[1]}: 20 TLS unary/server/client/bidi cycles, deadline/cancellation, untrusted CA and recovery passed\n";
