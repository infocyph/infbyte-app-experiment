<?php

declare(strict_types=1);

use Infocyph\Pathwise\Storage\StorageContext;
use Infocyph\Pathwise\Storage\StorageFactory;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\ZipArchive\FilesystemZipArchiveProvider;

function storageLocalModesWorkload(): void
{
    $root = evidenceDirectory() . '/storage-modes';
    verify(mkdir($root, 0700), 'Storage fixture directory failed.');
    $native = new LocalFilesystemAdapter($root . '/native');
    $first = new StorageContext([
        'files' => ['driver' => 'memory'],
        'prefix' => ['driver' => 'path-prefixing', 'constructor' => [$native, 'first']],
    ], 'files');
    $second = new StorageContext([
        'files' => ['driver' => 'inmemory'],
        'prefix' => ['driver' => 'path-prefixing', 'constructor' => [$native, 'second']],
    ], 'files');
    $first->filesystem()->write('same.txt', 'first');
    $second->filesystem()->write('same.txt', 'second');
    $first->filesystem('prefix')->write('same.txt', 'first');
    $second->filesystem('prefix')->write('same.txt', 'second');
    verify($first->filesystem()->read('same.txt') === 'first' && $second->filesystem()->read('same.txt') === 'second', 'Memory context crossed boundary.');
    verify(file_get_contents($root . '/native/first/same.txt') === 'first' && file_get_contents($root . '/native/second/same.txt') === 'second', 'Prefix writes crossed boundary.');
    $readOnly = StorageFactory::createFilesystem(['driver' => 'readonly', 'constructor' => [$native]]);
    verify($readOnly->read('first/same.txt') === 'first', 'Read-only read failed.');
    expectFailure(static fn() => $readOnly->write('first/same.txt', 'changed'), UnableToWriteFile::class, 'Read-only write accepted.');
    expectFailure(static fn() => $readOnly->delete('first/same.txt'), UnableToDeleteFile::class, 'Read-only delete accepted.');
    verify($readOnly->read('first/same.txt') === 'first', 'Denied write altered file.');
    $archive = $root . '/reports.zip';
    $zip = new StorageContext([
        'files' => ['driver' => 'zip', 'constructor' => [new FilesystemZipArchiveProvider($archive), 'first']],
        'foreign' => ['driver' => 'ziparchive', 'constructor' => [new FilesystemZipArchiveProvider($archive), 'second']],
    ], 'files');
    remoteStorageWorkload($zip);
    $physical = new ZipArchive();
    verify($physical->open($archive) === true && $physical->getFromName('second/reports/document.txt') === 'foreign', 'Native ZIP content mismatch.');
    $physical->close();
    expectFailure(static fn() => $first->resolve('files://../escape'), InvalidArgumentException::class, 'Logical traversal accepted.');
    expectFailure(static fn() => StorageFactory::createFilesystem(['driver' => 'local', 'root' => $root, 'adapter' => $native]), InvalidArgumentException::class, 'Ambiguous construction accepted.');
    echo "Memory, prefix, read-only, ZIP and context boundaries passed\n";
}

function additionalStorageContext(string $backend, bool $seed = false, ?int $proxyPort = null): StorageContext
{
    $bucket = 'lab-documents';
    $config = match ($backend) {
        'ftp' => static fn(string $prefix): array => ['driver' => 'ftp', 'constructor' => [
            new \League\Flysystem\Ftp\FtpConnectionOptions('127.0.0.1', '/' . $prefix, 'experiment', 'fixture-only-password', port: 12121, timeout: 3),
        ]],
        'webdav' => static fn(string $prefix): array => ['driver' => 'webdav', 'constructor' => [
            new \Sabre\DAV\Client(['baseUri' => 'http://127.0.0.1:' . ($proxyPort ?? 18085) . '/', 'userName' => 'experiment', 'password' => 'fixture-only-password']), $prefix,
        ]],
        'gridfs' => (static function () use ($bucket): Closure {
            $client = new \MongoDB\Client('mongodb://127.0.0.1:27017/?serverSelectionTimeoutMS=3000&connectTimeoutMS=2000&socketTimeoutMS=4000');
            $storage = $client->selectDatabase('experiment')->selectGridFSBucket(['bucketName' => 'documents']);
            return static fn(string $prefix): array => ['driver' => 'mongodb-gridfs', 'constructor' => [$storage, $prefix]];
        })(),
        'sftp2' => (static function (): Closure {
            $provider = new \League\Flysystem\PhpseclibV2\SftpConnectionProvider('127.0.0.1', 'experiment', (string) getenv('LAB_SFTP_SECRET'), port: 12222, timeout: 3);
            return static fn(string $prefix): array => ['driver' => 'sftp-v2', 'constructor' => [$provider, '/upload/' . $prefix]];
        })(),
        'async-s3' => (static function () use ($seed, $bucket, $proxyPort): Closure {
            $client = new \AsyncAws\S3\S3Client([
                'endpoint' => 'http://127.0.0.1:' . ($proxyPort ?? 19000), 'region' => 'us-east-1',
                'accessKeyId' => 'experiment', 'accessKeySecret' => getenv('LAB_S3_SECRET'), 'pathStyleEndpoint' => true,
            ]);
            if ($seed) {
                $client->createBucket(['Bucket' => $bucket])->resolve();
            }
            return static fn(string $prefix): array => ['driver' => 'async-aws-s3', 'constructor' => [$client, $bucket, $prefix]];
        })(),
        'azure' => (static function () use ($seed, $bucket, $proxyPort): Closure {
            $connection = 'DefaultEndpointsProtocol=http;AccountName=experiment;AccountKey=' . getenv('LAB_AZURE_KEY') . ';BlobEndpoint=http://127.0.0.1:' . ($proxyPort ?? 11000) . '/experiment;';
            $client = \MicrosoftAzure\Storage\Blob\BlobRestProxy::createBlobService($connection);
            if ($seed) {
                $client->createContainer($bucket);
            }
            return static fn(string $prefix): array => ['driver' => 'azure-blob-storage', 'constructor' => [$client, $bucket, $prefix]];
        })(),
        'gcs' => (static function () use ($seed, $bucket, $proxyPort): Closure {
            $endpoint = 'http://127.0.0.1:' . ($proxyPort ?? 14443);
            putenv('STORAGE_EMULATOR_HOST=' . $endpoint);
            $client = new \Google\Cloud\Storage\StorageClient(['projectId' => 'experiment', 'apiEndpoint' => $endpoint, 'retries' => 0, 'requestTimeout' => 4]);
            $storage = $seed ? $client->createBucket($bucket) : $client->bucket($bucket);
            return static fn(string $prefix): array => ['driver' => 'google-cloud-storage', 'constructor' => [$storage, $prefix]];
        })(),
        default => throw new InvalidArgumentException('Unknown additional storage backend.'),
    };
    return new StorageContext(['files' => $config('tenant-a'), 'foreign' => $config('tenant-b')], 'files');
}

function ftpWorkload(): void
{
    remoteStorageWorkload(additionalStorageContext('ftp', true));
}

function webdavWorkload(): void
{
    remoteStorageWorkload(additionalStorageContext('webdav', true));
}

function gridfsWorkload(): void
{
    remoteStorageWorkload(additionalStorageContext('gridfs', true));
}

function sftp2Workload(): void
{
    remoteStorageWorkload(additionalStorageContext('sftp2', true));
}

function asyncS3Workload(): void
{
    remoteStorageWorkload(additionalStorageContext('async-s3', true));
}

function azureWorkload(): void
{
    remoteStorageWorkload(additionalStorageContext('azure', true));
}

function gcsWorkload(): void
{
    remoteStorageWorkload(additionalStorageContext('gcs', true));
}

function additionalStorageCopyContracts(string $backend): void
{
    $context = additionalStorageContext($backend, true);
    $disk = $context->filesystem();
    $foreign = $context->filesystem('foreign');
    $prefix = 'orders/';
    $payloads = ['a.bin' => str_repeat("a\0\xff", 2048), 'nested/b.bin' => str_repeat('b', 3000)];
    foreach ($payloads as $path => $payload) {
        $disk->write($prefix . $path, $payload);
        $foreign->write($prefix . $path, 'foreign:' . $path);
    }
    $disk->write($prefix . 'copy.bin', 'previous');
    $disk->copy($prefix . 'a.bin', $prefix . 'copy.bin');
    $peer = additionalStorageContext($backend)->filesystem();
    verify($peer->read($prefix . 'a.bin') === $payloads['a.bin'] && $peer->read($prefix . 'copy.bin') === $payloads['a.bin'], 'Copy overwrote source or did not replace target.');
    $disk->move($prefix . 'copy.bin', $prefix . 'moved.bin');
    $expected = [
        $prefix . 'a.bin' => strlen($payloads['a.bin']),
        $prefix . 'nested/b.bin' => strlen($payloads['nested/b.bin']),
        $prefix . 'moved.bin' => strlen($payloads['a.bin']),
    ];
    $actual = [];
    foreach ($peer->listContents('orders', true) as $entry) {
        if ($entry->isFile()) {
            $actual[$entry->path()] = $peer->fileSize($entry->path());
        }
    }
    ksort($actual);
    ksort($expected);
    verify($actual === $expected && !$peer->fileExists($prefix . 'copy.bin'), 'Recursive listing/metadata did not reconcile exact files.');
    $disk->delete($prefix . 'moved.bin');
    verify(!$peer->fileExists($prefix . 'moved.bin') && $foreign->read($prefix . 'a.bin') === 'foreign:a.bin' && $foreign->read($prefix . 'nested/b.bin') === 'foreign:nested/b.bin', 'Copy/move/delete crossed prefix.');
    echo "$backend independent recursive listing, copy replacement, move and exact file sizes passed\n";
}

function additionalStorageStagedContracts(string $backend): void
{
    $context = additionalStorageContext($backend, true);
    $disk = $context->filesystem();
    $foreign = $context->filesystem('foreign');
    $final = 'orders/final.bin';
    $stage = 'orders/staging.bin';
    $payload = str_repeat("receipt\0\xff", 32768);
    $expected = hash('sha256', $payload);
    $disk->write($final, 'previous');
    $foreign->write($final, 'foreign');
    // Application-owned staged publication: a short, actually persisted upload
    // is rejected by independent digest/length checks, not assumed atomic.
    $disk->write($stage, substr($payload, 0, 65536));
    $peer = additionalStorageContext($backend)->filesystem();
    verify($peer->fileSize($stage) === 65536 && hash('sha256', $peer->read($stage)) !== $expected, 'Short persisted stage did not demonstrate corruption.');
    verify($peer->read($final) === 'previous', 'Incomplete staging changed final object.');
    $disk->delete($stage);
    verify(!$peer->fileExists($stage), 'Short staged object could not be cleaned.');
    $source = fopen('php://temp', 'w+b');
    verify(is_resource($source), 'Staged transfer source unavailable.');
    try {
        verify(fwrite($source, $payload) === strlen($payload) && rewind($source), 'Staged source preparation failed.');
        $disk->writeStream($stage, $source);
    } finally {
        if (is_resource($source)) {
            fclose($source);
        }
    }
    $read = $peer->readStream($stage);
    try {
        $digest = hash_init('sha256');
        verify(hash_update_stream($digest, $read) === strlen($payload) && hash_final($digest) === $expected, 'Independent stage digest mismatch.');
    } finally {
        if (is_resource($read)) {
            fclose($read);
        }
    }
    verify($peer->read($final) === 'previous', 'Verified stage changed final object before publication.');
    $disk->move($stage, $final);
    verify(!$peer->fileExists($stage) && hash('sha256', $peer->read($final)) === $expected && $foreign->read($final) === 'foreign', 'Publication or prefix reconciliation failed.');
    echo "$backend actual short stage, explicit digest validation, cleanup and recovered publication passed\n";
}
