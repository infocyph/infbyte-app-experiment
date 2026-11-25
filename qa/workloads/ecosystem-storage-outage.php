<?php

declare(strict_types=1);

use Infocyph\Pathwise\Storage\StorageContext;
use League\Flysystem\FilesystemException;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

function storageOutageContext(bool $seed): StorageContext
{
    $backend = (string) getenv('LAB_STORAGE_BACKEND');
    if (!in_array($backend, ['s3', 'sftp'], true)) {
        return additionalStorageContext($backend, $seed);
    }
    if ($backend === 's3') {
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

/**
 * Fixture-only stream wrapper: pause source consumption during a real SFTP upload.
 */
final class LabSftpGatedStream
{
    public mixed $context = null;

    /** @var resource|null */
    private $source = null;

    private bool $paused = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->source = fopen((string) getenv('LAB_SFTP_FAULT_ROOT') . '/payload.bin', 'rb');
        return is_resource($this->source);
    }

    public function stream_read(int $count): string|false
    {
        if (!$this->paused && ftell($this->source) >= 2 * 1024 * 1024) {
            $this->paused = true;
            $root = (string) getenv('LAB_SFTP_FAULT_ROOT');
            verify(touch($root . '/source-paused'), 'SFTP source barrier marker failed.');
            $deadline = hrtime(true) + 45_000_000_000;
            while (!is_file($root . '/source-release')) {
                verify(hrtime(true) < $deadline, 'SFTP source barrier timed out.');
                usleep(20_000);
            }
        }
        return fread($this->source, $count);
    }

    public function stream_eof(): bool
    {
        return feof($this->source);
    }

    public function stream_tell(): int|false
    {
        return ftell($this->source);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return fseek($this->source, $offset, $whence) === 0;
    }

    public function stream_stat(): array|false
    {
        return fstat($this->source);
    }

    public function stream_close(): void
    {
        if (is_resource($this->source)) {
            fclose($this->source);
        }
    }
}

function sftpPublicationFaultWorkload(string $phase): void
{
    putenv('LAB_STORAGE_BACKEND=sftp');
    $context = storageOutageContext(false);
    $disk = $context->filesystem();
    $foreign = $context->filesystem('foreign');
    $root = (string) getenv('LAB_SFTP_FAULT_ROOT');
    $final = 'receipts/stable.bin';
    $stage = 'receipts/staging.bin';

    if ($phase === 'seed') {
        $disk->write($final, 'previous');
        $foreign->write($final, 'foreign');
        verify(!$disk->fileExists($stage), 'Unexpected existing staging object.');
        return;
    }

    if ($phase === 'interrupt') {
        verify(stream_wrapper_register('labsftpgate', LabSftpGatedStream::class), 'Stream wrapper registration failed.');
        $stream = fopen('labsftpgate://payload', 'rb');
        verify(is_resource($stream), 'Gated source unavailable.');
        try {
            expectFailure(static fn() => $disk->writeStream($stage, $stream), FilesystemException::class, 'Interrupted SFTP upload reported success.');
            verify(is_file($root . '/source-paused') && is_file($root . '/source-release'), 'Upload failed before the intended fault.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            stream_wrapper_unregister('labsftpgate');
        }
        return;
    }

    verify($phase === 'recover', 'Unknown SFTP publication phase.');
    verify($disk->read($final) === 'previous' && $foreign->read($final) === 'foreign', 'Partial upload altered published or foreign data.');
    if ($disk->fileExists($stage)) {
        verify($disk->fileSize($stage) < filesize($root . '/payload.bin'), 'Interrupted staging unexpectedly contains the complete upload.');
        $disk->delete($stage);
    }
    verify(!$disk->fileExists($stage), 'Staging cleanup failed.');
    $stream = fopen($root . '/payload.bin', 'rb');
    verify(is_resource($stream), 'Retry source unavailable.');
    try {
        $disk->writeStream($stage, $stream);
    } finally {
        fclose($stream);
    }
    $read = $disk->readStream($stage);
    try {
        $hash = hash_init('sha256');
        verify(hash_update_stream($hash, $read) === filesize($root . '/payload.bin') && hash_final($hash) === hash_file('sha256', $root . '/payload.bin'), 'Retried staged upload did not reconcile.');
    } finally {
        fclose($read);
    }
    $disk->move($stage, 'receipts/recovered.bin');
    verify(!$disk->fileExists($stage) && $disk->fileSize('receipts/recovered.bin') === filesize($root . '/payload.bin'), 'Recovered publication identity failed.');
    $published = $disk->readStream('receipts/recovered.bin');
    try {
        $hash = hash_init('sha256');
        verify(hash_update_stream($hash, $published) === filesize($root . '/payload.bin') && hash_final($hash) === hash_file('sha256', $root . '/payload.bin'), 'Published digest mismatch.');
    } finally {
        fclose($published);
    }
    verify($disk->read($final) === 'previous' && $foreign->read($final) === 'foreign', 'Recovery crossed publication or tenant boundary.');
    echo "Real interrupted staged SFTP upload, explicit cleanup, retry and publication reconciled\n";
}

function sftpRenameFaultWorkload(string $phase, string $mode): void
{
    putenv('LAB_STORAGE_BACKEND=sftp');
    $context = storageOutageContext(false);
    $disk = $context->filesystem();
    $foreign = $context->filesystem('foreign');
    $payload = str_repeat("receipt\0\xff\n", 4096);
    verify(in_array($mode, ['before', 'after'], true), 'Unknown rename fault mode.');
    $source = 'receipts/' . $mode . '-stage.bin';
    $target = 'receipts/' . $mode . '-published.bin';
    if ($phase === 'seed') {
        $disk->write($source, $payload);
        $foreign->write($target, 'foreign');
        verify(!$disk->fileExists($target), 'Unexpected existing target.');
        return;
    }
    if ($phase === 'fault') {
        expectFailure(static fn() => $disk->move($source, $target), FilesystemException::class, 'Ambiguous SFTP move reported success.');
        return;
    }
    verify($phase === 'recover', 'Unknown rename phase.');
    if ($mode === 'before') {
        verify($disk->read($source) === $payload && !$disk->fileExists($target), 'Withheld rename modified publication.');
        $disk->move($source, $target);
    }
    verify(!$disk->fileExists($source) && $disk->read($target) === $payload, 'Rename reconciliation failed.');
    verify($foreign->read($target) === 'foreign', 'Rename crossed tenant boundary.');
    $disk->write('receipts/healthy-' . $mode, 'healthy');
    verify($disk->read('receipts/healthy-' . $mode) === 'healthy', 'Fresh storage client failed.');
    echo "SFTP $mode rename reconciliation passed\n";
}
