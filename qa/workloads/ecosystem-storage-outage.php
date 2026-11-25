<?php

declare(strict_types=1);

use Infocyph\Pathwise\Storage\StorageContext;
use League\Flysystem\FilesystemException;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

function storageOutageContext(bool $seed): StorageContext
{
    if (getenv('LAB_STORAGE_BACKEND') === 's3') {
        $client = new Aws\S3\S3Client([
            'version' => 'latest', 'region' => 'us-east-1',
            'endpoint' => 'http://127.0.0.1:19000', 'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'experiment', 'secret' => getenv('LAB_S3_SECRET')],
            'retries' => 0, 'http' => ['connect_timeout' => 2, 'timeout' => 4],
        ]);
        if ($seed) {
            $client->createBucket(['Bucket' => 'lab-documents']);
        }
        $config = static fn(string $prefix): array => ['driver' => 'aws-s3', 'constructor' => [$client, 'lab-documents', $prefix]];
    } else {
        $pins = preg_split('/\s+/', trim((string) getenv('LAB_SFTP_HOST_KEYS')), -1, PREG_SPLIT_NO_EMPTY);
        verify(is_array($pins) && $pins !== [], 'Missing out-of-band trust.');
        $provider = new SftpConnectionProvider('127.0.0.1', 'experiment', (string) getenv('LAB_SFTP_SECRET'), port: 12222, timeout: 3, maxTries: 1, hostFingerprint: $pins);
        $config = static fn(string $prefix): array => ['driver' => 'sftp-v3', 'constructor' => [$provider, '/upload/' . $prefix]];
    }

    return new StorageContext(['files' => $config('tenant-a'), 'foreign' => $config('tenant-b')], 'files');
}

function storageOutageWorkload(string $phase): void
{
    $context = storageOutageContext($phase === 'seed');
    $disk = $context->filesystem();
    $foreign = $context->filesystem('foreign');
    $payload = str_repeat("\x00\xffpayload\r\n", 65536);
    $key = 'receipts/stable.bin';
    if ($phase === 'seed') {
        $disk->write($key, $payload);
        $foreign->write($key, 'foreign');
        verify($disk->read($key) === $payload && $foreign->read($key) === 'foreign', 'Seed integrity or isolation failed.');
    } elseif ($phase === 'denied') {
        foreach ([
            static fn() => $disk->read($key),
            static fn() => $disk->write($key, 'forbidden-overwrite'),
            static fn() => $disk->write('receipts/failed.bin', 'forbidden-new-object'),
            static fn() => $disk->delete($key),
        ] as $operation) {
            expectFailure($operation, FilesystemException::class, 'Offline storage operation reported success.');
        }
    } elseif ($phase === 'recover') {
        verify($disk->read($key) === $payload && $foreign->read($key) === 'foreign', 'Failed operation mutated committed storage.');
        verify(!$disk->fileExists('receipts/failed.bin'), 'Offline new object appeared.');
        $stream = fopen('php://temp', 'w+b');
        verify(is_resource($stream), 'Transfer stream unavailable.');
        try {
            verify(fwrite($stream, $payload) === strlen($payload) && rewind($stream), 'Transfer preparation failed.');
            $disk->writeStream('receipts/retry.bin', $stream);
        } finally {
            fclose($stream);
        }
        $read = $disk->readStream('receipts/retry.bin');
        try {
            $digest = hash_init('sha256');
            verify(hash_update_stream($digest, $read) === strlen($payload) && hash_final($digest) === hash('sha256', $payload), 'Recovered stream integrity failed.');
        } finally {
            fclose($read);
        }
        $disk->move('receipts/retry.bin', 'receipts/committed.bin');
        verify(!$disk->fileExists('receipts/retry.bin') && $disk->read('receipts/committed.bin') === $payload, 'Recovered publication failed.');
        $disk->delete('receipts/committed.bin');
        verify($foreign->read($key) === 'foreign' && $disk->read($key) === $payload, 'Recovered mutation crossed boundary.');
    } else {
        throw new InvalidArgumentException('Unknown storage phase.');
    }
    echo "Storage outage phase $phase passed\n";
}


function s3PublicationFaultWorkload(): void
{
    putenv('LAB_STORAGE_BACKEND=s3');
    $context = storageOutageContext(true);
    $direct = $context->filesystem();
    $foreign = $context->filesystem('foreign');
    $client = new Aws\S3\S3Client([
        'version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:19001',
        'use_path_style_endpoint' => true, 'credentials' => ['key' => 'experiment', 'secret' => getenv('LAB_S3_SECRET')],
        'retries' => 0, 'request_checksum_calculation' => 'when_required',
        'http' => ['connect_timeout' => 2, 'timeout' => 12, 'expect' => false],
    ]);
    $fault = new StorageContext(['files' => ['driver' => 'aws-s3', 'constructor' => [$client, 'lab-documents', 'tenant-a']]], 'files')->filesystem();
    $payload = str_repeat("\x00\xffreceipt-2026\r\n", 65536);
    $digest = hash('sha256', $payload);
    foreach (['interrupted', 'uncertain'] as $mode) {
        $key = 'receipts/' . $mode . '.bin';
        $direct->write($key, 'previous');
        $foreign->write($key, 'foreign');
        $stream = fopen('php://temp', 'w+b');
        verify(is_resource($stream), 'Upload stream unavailable.');
        try {
            verify(fwrite($stream, $payload) === strlen($payload) && rewind($stream), 'Upload source preparation failed.');
            expectFailure(static fn() => $fault->writeStream($key, $stream), FilesystemException::class, 'Failed transfer reported success.');
        } finally {
            fclose($stream);
        }
        $marker = evidenceDirectory() . '/s3-' . $mode;
        verify(is_file($marker), 'Expected transport fault was not reached.');
        $expected = $mode === 'interrupted' ? '65536/' . strlen($payload) : '200 ' . strlen($payload) . '/' . strlen($payload);
        verify(trim((string) file_get_contents($marker)) === $expected, 'Transport fault evidence differs.');
        $actual = $direct->read($key);
        if ($mode === 'interrupted') {
            verify($actual === 'previous', 'Incomplete PUT published partial object.');
            $direct->write($key, $payload);
        } else {
            verify(hash('sha256', $actual) === $digest, 'Successful origin PUT did not survive response loss.');
        }
        verify(hash('sha256', $direct->read($key)) === $digest && $foreign->read($key) === 'foreign', 'Explicit reconciliation or tenant isolation failed.');
    }
    echo "Real S3 interrupted body and lost successful response, exact bytes and tenant isolation passed\n";
}
