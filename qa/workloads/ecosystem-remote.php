<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\Pathwise\Storage\StorageContext;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

function remoteCache(string $backend, string $namespace): Cache
{
    $options = new CacheOptions(failOpen: false);

    return match ($backend) {
        'cluster' => Cache::redisCluster($namespace, ['127.0.0.1:17379', '127.0.0.1:17380', '127.0.0.1:17381'], options: $options),
        'mongo' => Cache::mongodb($namespace, database: 'experiment', options: $options),
        default => throw new InvalidArgumentException('Unknown remote cache.'),
    };
}

function redisClusterWorkload(): void
{
    remoteCacheWorkload('cluster');
}

function mongoWorkload(): void
{
    remoteCacheWorkload('mongo');
}

function remoteCacheWorkload(string $backend): void
{
    $results = parallelRace(static function (int $worker) use ($backend): int {
        $cache = remoteCache($backend, 'lab-remote');
        $atomic = $cache->atomic();
        verify($atomic !== null, 'Authoritative atomic capability missing.');
        $won = $atomic->setIfAbsent('claim', $worker, 300);
        $values = [];
        for ($i = 0; $i < 100; ++$i) {
            $values['worker-' . $worker . '-' . $i] = [$worker, $i];
        }
        verify($cache->setMultiple($values, 300), 'Remote batch write failed.');
        verify(iterator_to_array($cache->getMultiple(array_keys($values))) === $values, 'Remote batch read mismatch.');

        return (int) $won;
    }, 'remote-claim');
    verify(array_sum($results) === 1, 'Cross-process conditional claim had wrong winner count.');
    $cache = remoteCache($backend, 'lab-remote');
    $foreign = remoteCache($backend, 'lab-foreign');
    $foreign->set('claim', 'foreign', 300);
    $atomic = $cache->atomic();
    verify($atomic !== null, 'Atomic capability missing.');
    $winner = $cache->get('claim');
    verify(is_int($winner) && $atomic->compareAndSet('claim', $winner, 'complete', 300), 'Remote CAS failed.');
    verify(!$atomic->compareAndSet('claim', $winner, 'stale', 300), 'Stale remote CAS succeeded.');
    verify($atomic->getAndDelete('claim') === 'complete' && $atomic->getAndDelete('claim') === null, 'Remote consume replay accepted.');
    for ($worker = 0; $worker < 12; ++$worker) {
        for ($i = 0; $i < 100; ++$i) {
            verify($cache->get('worker-' . $worker . '-' . $i) === [$worker, $i], 'Durable remote key missing.');
        }
    }
    $cache->remember('tagged', static fn(): string => 'value', 300, ['group']);
    verify($cache->invalidateTag('group') && !$cache->has('tagged'), 'Remote tag invalidation failed.');
    verify($cache->clear() && !$cache->has('worker-0-0') && $foreign->get('claim') === 'foreign', 'Remote namespace clear crossed boundary.');
    echo "Real remote cache: 1200 keys, 12-worker atomic claim, strict CAS, consume and namespace/tag invalidation passed\n";
}

function objectStorageWorkload(): void
{
    $client = new Aws\S3\S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'endpoint' => 'http://127.0.0.1:19000',
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => 'experiment', 'secret' => getenv('LAB_S3_SECRET')],
        'http' => ['connect_timeout' => 3, 'timeout' => 15],
    ]);
    $client->createBucket(['Bucket' => 'lab-documents']);
    $context = new StorageContext([
        'files' => ['driver' => 'aws-s3', 'constructor' => [$client, 'lab-documents', 'tenant-a']],
        'foreign' => ['driver' => 'aws-s3', 'constructor' => [$client, 'lab-documents', 'tenant-b']],
    ], 'files');
    remoteStorageWorkload($context);
}

function sftpWorkload(): void
{
    $provider = new SftpConnectionProvider('127.0.0.1', 'experiment', (string) getenv('LAB_SFTP_SECRET'), port: 12222, timeout: 10);
    $context = new StorageContext([
        'files' => ['driver' => 'sftp-v3', 'constructor' => [$provider, '/upload/tenant-a']],
        'foreign' => ['driver' => 'sftp-v3', 'constructor' => [$provider, '/upload/tenant-b']],
    ], 'files');
    remoteStorageWorkload($context);
}

function remoteStorageWorkload(StorageContext $context): void
{
    $disk = $context->filesystem();
    $foreign = $context->filesystem('foreign');
    $payload = str_repeat("0123456789abcdef", 65536);
    $stream = fopen('php://temp', 'w+b');
    verify(is_resource($stream), 'Cannot open fixture.');
    try {
        verify(fwrite($stream, $payload) === strlen($payload), 'Short fixture write.');
        rewind($stream);
        $disk->writeStream('reports/document.txt', $stream);
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
    $foreign->write('reports/document.txt', 'foreign');
    verify($disk->fileSize('reports/document.txt') === strlen($payload) && $foreign->read('reports/document.txt') === 'foreign', 'Remote namespace size/isolation failed.');
    $source = $disk->readStream('reports/document.txt');
    try {
        $digest = hash_init('sha256');
        verify(hash_update_stream($digest, $source) === strlen($payload) && hash_final($digest) === hash('sha256', $payload), 'Remote stream integrity failed.');
    } finally {
        if (is_resource($source)) {
            fclose($source);
        }
    }
    $download = new DownloadProcessor();
    $download->setStorageContext($context);
    $download->setAllowedRoots(['files://reports']);
    $prepared = $download->prepareDownload('files://reports/document.txt', 'document.txt', 'bytes=100-999');
    $body = '';
    foreach ($download->streamChunks($prepared) as $chunk) {
        $body .= $chunk;
    }
    verify($prepared->status === 206 && $body === substr($payload, 100, 900), 'Remote range body failed.');
    expectFailure(static fn() => $context->localPath('reports/document.txt'), InvalidArgumentException::class, 'Remote storage claimed local path.');
    $disk->move('reports/document.txt', 'reports/complete.txt');
    verify(!$disk->fileExists('reports/document.txt') && $disk->read('reports/complete.txt') === $payload, 'Remote move reconciliation failed.');
    $disk->delete('reports/complete.txt');
    verify(!$disk->fileExists('reports/complete.txt') && $foreign->read('reports/document.txt') === 'foreign', 'Remote delete crossed tenant prefix.');
    echo "Real remote storage stream, range, isolation, move and deletion passed\n";
}
