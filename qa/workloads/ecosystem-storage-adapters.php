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

function ftpWorkload(): void
{
    $configuration = static fn(string $root): array => ['driver' => 'ftp', 'constructor' => [new League\Flysystem\Ftp\FtpConnectionOptions('127.0.0.1', $root, 'experiment', 'fixture-only-password', port: 12121, timeout: 10)]];
    remoteStorageWorkload(new StorageContext(['files' => $configuration('/tenant-a'), 'foreign' => $configuration('/tenant-b')], 'files'));
}

function webdavWorkload(): void
{
    $client = new Sabre\DAV\Client(['baseUri' => 'http://127.0.0.1:18085/', 'userName' => 'experiment', 'password' => 'fixture-only-password']);
    remoteStorageWorkload(new StorageContext([
        'files' => ['driver' => 'webdav', 'constructor' => [$client, 'tenant-a']],
        'foreign' => ['driver' => 'webdav', 'constructor' => [$client, 'tenant-b']],
    ], 'files'));
}

function gridfsWorkload(): void
{
    $client = new MongoDB\Client('mongodb://127.0.0.1:27017');
    $bucket = $client->selectDatabase('experiment')->selectGridFSBucket(['bucketName' => 'documents']);
    remoteStorageWorkload(new StorageContext([
        'files' => ['driver' => 'mongodb-gridfs', 'constructor' => [$bucket, 'tenant-a']],
        'foreign' => ['driver' => 'mongodb-gridfs', 'constructor' => [$bucket, 'tenant-b']],
    ], 'files'));
}

function sftp2Workload(): void
{
    $provider = new League\Flysystem\PhpseclibV2\SftpConnectionProvider('127.0.0.1', 'experiment', (string) getenv('LAB_SFTP_SECRET'), port: 12222, timeout: 10);
    remoteStorageWorkload(new StorageContext([
        'files' => ['driver' => 'sftp-v2', 'constructor' => [$provider, '/upload/tenant-a']],
        'foreign' => ['driver' => 'sftp-v2', 'constructor' => [$provider, '/upload/tenant-b']],
    ], 'files'));
}

function asyncS3Workload(): void
{
    $client = new AsyncAws\S3\S3Client([
        'endpoint' => 'http://127.0.0.1:19000',
        'region' => 'us-east-1',
        'accessKeyId' => 'experiment',
        'accessKeySecret' => getenv('LAB_S3_SECRET'),
        'pathStyleEndpoint' => true,
    ]);
    $client->createBucket(['Bucket' => 'lab-documents'])->resolve();
    remoteStorageWorkload(new StorageContext([
        'files' => ['driver' => 'async-aws-s3', 'constructor' => [$client, 'lab-documents', 'tenant-a']],
        'foreign' => ['driver' => 'async-aws-s3', 'constructor' => [$client, 'lab-documents', 'tenant-b']],
    ], 'files'));
}

function azureWorkload(): void
{
    $connection = 'DefaultEndpointsProtocol=http;AccountName=experiment;AccountKey=' . getenv('LAB_AZURE_KEY') . ';BlobEndpoint=http://127.0.0.1:11000/experiment;';
    $client = MicrosoftAzure\Storage\Blob\BlobRestProxy::createBlobService($connection);
    $client->createContainer('lab-documents');
    remoteStorageWorkload(new StorageContext([
        'files' => ['driver' => 'azure-blob-storage', 'constructor' => [$client, 'lab-documents', 'tenant-a']],
        'foreign' => ['driver' => 'azure-blob-storage', 'constructor' => [$client, 'lab-documents', 'tenant-b']],
    ], 'files'));
}

function gcsWorkload(): void
{
    putenv('STORAGE_EMULATOR_HOST=http://127.0.0.1:14443');
    $client = new Google\Cloud\Storage\StorageClient(['projectId' => 'experiment', 'apiEndpoint' => 'http://127.0.0.1:14443']);
    $bucket = $client->createBucket('lab-documents');
    remoteStorageWorkload(new StorageContext([
        'files' => ['driver' => 'google-cloud-storage', 'constructor' => [$bucket, 'tenant-a']],
        'foreign' => ['driver' => 'google-cloud-storage', 'constructor' => [$bucket, 'tenant-b']],
    ], 'files'));
}
