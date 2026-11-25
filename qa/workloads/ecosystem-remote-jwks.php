<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\Epicrypt\Exception\ConfigurationException;
use Infocyph\Epicrypt\Exception\Token\KeyResolutionException;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\Jwks;
use Infocyph\Epicrypt\Token\Jwt\RemoteJoseHostResolverInterface;
use Infocyph\Epicrypt\Token\Jwt\RemoteJwks;
use Infocyph\Epicrypt\Token\Jwt\RemoteJwksConfiguration;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Test-only transport: only the fixed fixture origin can be reached. */
final class LabJwksTransport implements ClientInterface
{
    public int $requests = 0;

    public function __construct(private readonly string $certificate, private readonly int $port) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        verify($request->getMethod() === 'GET' && $uri->getScheme() === 'https' && $uri->getHost() === 'issuer.example.test' && $uri->getPort() === $this->port, 'Fixture transport destination rejected.');
        ++$this->requests;
        $headers = [];
        $curl = curl_init((string) $uri);
        verify($curl instanceof CurlHandle, 'TLS fixture client initialization failed.');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => ['issuer.example.test:' . $this->port . ':127.0.0.1'],
            CURLOPT_CAINFO => $this->certificate,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: ' . $request->getHeaderLine('Accept')],
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[trim($name)][] = trim($value);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($curl);
        verify(is_string($body), 'TLS fixture request failed.');
        return new Response((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $headers, $body);
    }
}

final class LabJwksClock implements ClockInterface
{
    public function __construct(public int $timestamp) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->timestamp);
    }
}

final class LabJwksResolver implements RemoteJoseHostResolverInterface
{
    /** @param list<string> $addresses */
    public function __construct(private readonly array $addresses) {}

    public function resolve(string $hostname): array
    {
        verify($hostname === 'issuer.example.test', 'Unexpected logical resolver host.');

        return $this->addresses;
    }
}

function remoteJwksWorkload(): void
{
    $root = (string) getenv('LAB_JWKS_ROOT');
    $port = (int) getenv('LAB_JWKS_PORT');
    $issuer = 'https://issuer.example.test:' . $port;
    $algorithm = AsymmetricJwtAlgorithm::RS256;
    $jwk = new Jwks();
    [, $oldPublic] = josePemPair(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    [, $newPublic] = josePemPair(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $old = ['keys' => [$jwk->exportPublicKeyToJwk($oldPublic, 'old', $algorithm)]];
    $new = ['keys' => [$jwk->exportPublicKeyToJwk($newPublic, 'new', $algorithm)]];
    $publish = static function (array $document, int $status = 200, string $cache = 'max-age=60') use ($root): void {
        $data = json_encode(['document' => $document, 'status' => $status, 'cache' => $cache], JSON_THROW_ON_ERROR);
        verify(file_put_contents($root . '/response.tmp', $data, LOCK_EX) === strlen($data) && rename($root . '/response.tmp', $root . '/response.json'), 'Peer response publication failed.');
    };
    $transport = new LabJwksTransport($root . '/cert.pem', $port);
    $factory = new Psr17Factory();
    $cache = Cache::redis('remote-jwks', options: new CacheOptions(failOpen: false));
    $clock = new LabJwksClock(time());
    $publicResolver = new LabJwksResolver(['1.1.1.1']);
    $config = new RemoteJwksConfiguration($issuer, $issuer . '/jwks', minimumTtl: 2, maximumTtl: 2, staleTtl: 60, maximumResponseBytes: 4096, maximumKeys: 2);
    $remote = new RemoteJwks($transport, $factory, $config, $cache, $clock, $publicResolver);
    $publish($old);
    verify($remote->load() === $old && $transport->requests === 1, 'Initial JWKS fetch mismatch.');
    $publish($new);
    verify($remote->load() === $old && $transport->requests === 1, 'Fresh cache did not prevent fetch.');
    verify(str_contains($remote->resolve('new', $algorithm), 'PUBLIC KEY') && $transport->requests === 2, 'Missing kid did not refresh exactly once.');
    $replacement = new RemoteJwks($transport, $factory, $config, $cache, $clock, $publicResolver);
    verify($replacement->load() === $new && $transport->requests === 2, 'Replacement resolver did not reuse cache.');
    $publish([], 503);
    $clock->timestamp += 3;
    verify($replacement->load() === $new && $transport->requests === 3, 'Bounded stale fallback failed.');
    $clock->timestamp += 60;
    expectFailure($replacement->load(...), KeyResolutionException::class, 'Expired stale key set accepted.');
    $publish($new, cache: 'no-store');
    verify($replacement->load(true) === $new && $replacement->load() === $new && $transport->requests === 6, 'No-store response was cached.');
    $publish(['keys' => [$old['keys'][0], $new['keys'][0], $old['keys'][0]]]);
    expectFailure(static fn() => $replacement->load(true), KeyResolutionException::class, 'Key count limit bypassed.');
    $publish(['keys' => [], 'oversize' => str_repeat('x', 5000)]);
    expectFailure(static fn() => $replacement->load(true), KeyResolutionException::class, 'Response size limit bypassed.');
    $publish($new);
    $before = $transport->requests;
    foreach ([[], ['127.0.0.1'], ['1.1.1.1', '10.0.0.1'], ['::1']] as $addresses) {
        $blocked = new RemoteJwks($transport, $factory, $config, hostResolver: new LabJwksResolver($addresses));
        expectFailure($blocked->load(...), KeyResolutionException::class, 'Unsafe DNS answer accepted.');
    }
    verify($transport->requests === $before, 'Rejected DNS targets reached transport.');
    expectFailure(static fn() => new RemoteJwksConfiguration($issuer, 'https://foreign.example.test/jwks'), ConfigurationException::class, 'Foreign JWKS host accepted.');
    $discovery = new RemoteJwks($transport, $factory, new RemoteJwksConfiguration($issuer), hostResolver: $publicResolver);
    verify($discovery->load() === $new, 'OIDC discovery did not resolve bound issuer.');
    echo "Real fixture TLS, Redis cache, key rollover, stale/no-store, bounds, discovery and blocked DNS policy passed\n";
}
