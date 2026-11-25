<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use Infocyph\Epicrypt\Exception\Token\KeyResolutionException;
use Infocyph\Epicrypt\Token\Jwt\RemoteJwks;
use Infocyph\Epicrypt\Token\Jwt\RemoteJwksConfiguration;
use Nyholm\Psr7\Factory\Psr17Factory;
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

$root = (string) getenv('LAB_JWKS_DNS_ROOT');
$mode = $argv[1] ?? '';
$host = match ($mode) {
    'public', 'rebind' => 'issuer.example.test',
    'private' => 'private.example.test',
    'mixed' => 'mixed.example.test',
    'empty' => 'empty.example.test',
    default => throw new InvalidArgumentException('Unknown DNS fixture mode.'),
};
$client = new Client([
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
echo json_encode(['mode' => $mode, 'rejected' => $rejected, 'dispatches' => $transport->requests, 'public_requests' => $public, 'private_requests' => $private, 'connected_peers' => $peers], JSON_THROW_ON_ERROR), PHP_EOL;
dnsCheck($private === 0 && !in_array('127.0.0.2', $peers, true), 'Remote JWKS reached disallowed peer.');
if ($mode === 'public') {
    dnsCheck(!$rejected && $result === ['keys' => []] && $transport->requests === 1 && $public === 1 && $peers === ['93.184.216.34'], 'Actual approved TLS peer request did not reconcile.');
} elseif ($mode === 'rebind') {
    dnsCheck(($rejected || ($result === ['keys' => []] && $public === 1)) && $transport->requests === 1, 'Changed DNS answer did not fail closed or retain original peer.');
} else {
    dnsCheck($rejected && $transport->requests === 0 && $public === 0 && $peers === [], 'Invalid DNS answer reached HTTP transport.');
}
