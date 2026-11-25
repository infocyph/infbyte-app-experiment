<?php

declare(strict_types=1);

use Infocyph\Runwire\DatagramServer;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Datagram;
use Infocyph\Runwire\Network\DatagramListener;
use Infocyph\Runwire\Network\DatagramOptions;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Protocol\FramedConnection;
use Infocyph\Runwire\Protocol\LineCodec;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\StreamServer;
use Infocyph\Runwire\WebSocket\WebSocketMessage;
use Infocyph\Runwire\WebSocket\WebSocketSession;
use Infocyph\Runwire\WebSocket\WebSocketUpgrade;

require getenv('PROJECT') . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
$root = (string) getenv('LAB_WIRE_ROOT');
$codec = static fn(): LineCodec => new LineCodec(maxFrameBytes: 4096);
$echo = static function (string $frame, FramedConnection $connection): void {
    if (!$connection->send('echo:' . $frame)->accepted()) {
        $connection->abort();
    }
};
$http = static function (HttpRequest $request, ResponseWriterInterface $writer): void {
    if ($request->target === '/ws') {
        $session = WebSocketUpgrade::accept($request, $writer, originPolicy: static fn(string $origin): bool => $origin === 'https://lab.example');
        if ($session instanceof WebSocketSession) {
            $session->onMessage(static function (WebSocketSession $session, WebSocketMessage $message): void {
                $result = $message->binary ? $session->sendBinary($message->data) : $session->sendText($message->data);
                if (!$result->accepted()) {
                    $session->close(1011, 'write rejected');
                }
            });
        }

        return;
    }
    $body = $request->version->value . ':' . $request->target;
    $writer->start(200, Headers::fromArray(['Content-Length' => (string) strlen($body), 'Content-Type' => 'text/plain']));
    $writer->end($body);
};
$server = match ($mode) {
    'tcp' => StreamServer::tcp('127.0.0.1:18785', $codec, $echo),
    'unix' => StreamServer::unix($root . '/peer.sock', $codec, $echo),
    'udp' => new DatagramServer('udp', '127.0.0.1:18785', static function (Datagram $datagram, DatagramListener $listener): void {
        $listener->sendTo($datagram->payload, $datagram->peerAddress);
    }, options: new DatagramOptions(maxDatagramBytes: 512)),
    'websocket' => Server::http('127.0.0.1:18785', $http),
    'http3' => (new Server('quic', '127.0.0.1:18785', $http, tls: new TlsOptions($root . '/cert.pem', $root . '/key.pem')))->withHttp3(),
    'mtls' => new Server('mutual-tls', '127.0.0.1:18785', $http, tls: new TlsOptions($root . '/cert.pem', $root . '/key.pem', extraContext: ['cafile' => $root . '/ca.pem'], requireClientCertificate: true)),
    'http2' => new Server('tls', '127.0.0.1:18785', $http, tls: new TlsOptions($root . '/cert.pem', $root . '/key.pem')),
    default => throw new InvalidArgumentException('Unknown wire workload.'),
};
Runtime::create()->listen($server)->run();
