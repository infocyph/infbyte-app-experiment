<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use Infocyph\Epicrypt\Exception\Token\KeyResolutionException;
use Infocyph\Epicrypt\Token\Jwt\RemoteJwks;
use Infocyph\Epicrypt\Token\Jwt\RemoteJwksConfiguration;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Client\ClientDestinationPolicy;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http1ConnectedExchange;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\OutboundAddressPolicy;
use Infocyph\Runwire\Network\TlsClientOptions;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require getenv('PROJECT') . '/vendor/autoload.php';

function dnsCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Test-only PSR-18 bridge, exercising real Runwire DNS, peer verification and TLS. */
function runwireJwksTransport(string $root, string $host): ClientInterface
{
    return new class ($root, $host) implements ClientInterface {
        public function __construct(private readonly string $root, private readonly string $host) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $uri = 'https://' . $this->host . ':18443/jwks';
            dnsCheck($request->getMethod() === 'GET' && (string) $request->getUri() === $uri, 'Unexpected JWKS request.');
            $message = new ClientRequest(
                'GET', $uri, Headers::fromArray($request->getHeaders()),
                protocol: new ClientProtocolPolicy(
                    ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1],
                    destinationPolicy: new ClientDestinationPolicy([$uri]),
                ),
            );
            $loop = new SelectLoop();
            $executor = new ProcessExecutor($loop, 1, 2);
            $numeric = new Dialer($loop);
            $race = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
            $owned = null;
            $response = null;
            $failure = null;
            $completed = 0;
            $status = 0;
            $headers = [];
            $body = '';
            $finish = static function (?Connection $connection, ?Throwable $error) use (&$completed, &$failure, &$response, &$status, &$headers, &$body, $loop): void {
                ++$completed;
                $failure = $error;
                if ($error === null) {
                    $response = new Response($status, $headers, $body);
                }
                $connection?->abort();
                $loop->stop();
            };
            $watchdog = $loop->delay(8.0, static fn() => $loop->stop());
            try {
                $race->connect(
                    'tcp://' . $this->host . ':18443',
                    function (?Connection $connection, ?Throwable $error) use ($loop, $message, $finish, &$owned, &$status, &$headers, &$body): void {
                        if ($connection === null || $error !== null) {
                            $finish(null, $error ?? new RuntimeException('Runwire dial failed.'));
                            return;
                        }
                        $owned = $connection;
                        try {
                            $peer = $connection->peerAddress();
                            dnsCheck(is_string($peer) && str_ends_with($peer, ':18443'), 'Missing verified kernel peer.');
                            file_put_contents($this->root . '/connected-peers', substr($peer, 0, -6) . PHP_EOL, FILE_APPEND | LOCK_EX);
                            $exchange = new Http1ConnectedExchange(
                                $loop, $connection, $message,
                                static function (int $code, Headers $received) use (&$status, &$headers): void {
                                    $status = $code;
                                    foreach ($received->fields() as $field) {
                                        $headers[$field->name][] = $field->value;
                                    }
                                },
                                static function (string $chunk) use (&$body): void {
                                    dnsCheck(strlen($body) + strlen($chunk) <= 4096, 'JWKS fixture body limit exceeded.');
                                    $body .= $chunk;
                                },
                                $finish,
                            );
                            $exchange->start();
                        } catch (Throwable $error) {
                            $finish(null, $error);
                        }
                    },
                    new DialOptions(
                        connectTimeoutSeconds: 3.0,
                        tls: new TlsClientOptions($this->host, caFile: $this->root . '/cert.pem', alpnProtocols: ['http/1.1']),
                        addressPolicy: new OutboundAddressPolicy(),
                    ),
                );
                $loop->run();
            } finally {
                $loop->cancel($watchdog);
                $owned?->abort();
                $race->close();
                $numeric->close();
                $executor->close();
            }
            dnsCheck($completed === 1 && $race->pendingCount() === 0 && $numeric->pendingCount() === 0
                && $loop->diagnostics()->readWatchers === 0 && $loop->diagnostics()->writeWatchers === 0
                && $loop->diagnostics()->timersActive === 0, 'Runwire JWKS did not settle all ownership.');
            if ($failure !== null) {
                throw $failure;
            }
            dnsCheck($response instanceof ResponseInterface, 'Runwire JWKS response missing.');
            return $response;
        }
    };
}

$root = (string) getenv('LAB_JWKS_DNS_ROOT');
$mode = $argv[1] ?? '';
$host = match ($mode) {
    'public', 'rebind' => 'issuer.example.test',
    'private' => 'private.example.test',
    'mixed' => 'mixed.example.test',
    'empty' => 'empty.example.test',
    default => throw new InvalidArgumentException('Unknown DNS fixture mode.'),
};
$transportMode = $argv[2] ?? 'guzzle';
dnsCheck(in_array($transportMode, ['guzzle', 'runwire'], true), 'Unknown JWKS transport mode.');
$client = $transportMode === 'runwire' ? runwireJwksTransport($root, $host) : new Client([
    'verify' => $root . '/cert.pem',
    'connect_timeout' => 2,
    'timeout' => 5,
    'proxy' => '',
    'allow_redirects' => false,
    'curl' => [CURLOPT_DNS_CACHE_TIMEOUT => 0, CURLOPT_FRESH_CONNECT => true],
    'on_stats' => static function (TransferStats $stats) use ($root): void {
        $peer = $stats->getHandlerStat('primary_ip');
        if (is_string($peer) && $peer !== '') {
            file_put_contents($root . '/connected-peers', $peer . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    },
]);
$transport = new class ($client, $root, $mode) implements ClientInterface {
    public int $requests = 0;

    public function __construct(private readonly ClientInterface $client, private readonly string $root, private readonly string $mode) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        ++$this->requests;
        // Control the DNS fixture between the actual resolver and actual HTTP client.
        // Do not rewrite the request, connection target, certificate policy or response.
        if ($this->mode === 'rebind') {
            dnsCheck(file_put_contents($this->root . '/phase', 'private-peer', LOCK_EX) === 12, 'DNS phase switch failed.');
        }
        return $this->client->sendRequest($request);
    }
};
$issuer = 'https://' . $host . ':18443';
$remote = new RemoteJwks($transport, new Psr17Factory(), new RemoteJwksConfiguration($issuer, $issuer . '/jwks'));
$result = null;
$rejected = false;
try {
    $result = $remote->load();
} catch (KeyResolutionException) {
    $rejected = true;
}
$public = is_file($root . '/public.requests') ? count(file($root . '/public.requests')) : 0;
$private = is_file($root . '/private.requests') ? count(file($root . '/private.requests')) : 0;
$peers = is_file($root . '/connected-peers') ? file($root . '/connected-peers', FILE_IGNORE_NEW_LINES) : [];
echo json_encode(['mode' => $mode, 'transport' => $transportMode, 'rejected' => $rejected, 'dispatches' => $transport->requests, 'public_requests' => $public, 'private_requests' => $private, 'connected_peers' => $peers], JSON_THROW_ON_ERROR), PHP_EOL;
dnsCheck($private === 0 && !in_array('127.0.0.2', $peers, true), 'Remote JWKS reached disallowed peer.');
if ($mode === 'public') {
    dnsCheck(!$rejected && $result === ['keys' => []] && $transport->requests === 1 && $public === 1 && $peers === ['93.184.216.34'], 'Actual approved TLS peer request did not reconcile.');
} elseif ($mode === 'rebind') {
    dnsCheck(($rejected || ($result === ['keys' => []] && $public === 1)) && $transport->requests === 1, 'Changed DNS answer did not fail closed or retain original peer.');
} else {
    dnsCheck($rejected && $transport->requests === 0 && $public === 0 && $peers === [], 'Invalid DNS answer reached HTTP transport.');
}
