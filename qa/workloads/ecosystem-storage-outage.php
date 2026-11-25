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
