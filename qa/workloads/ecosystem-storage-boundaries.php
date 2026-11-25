<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\TransactionStateException;
use Infocyph\Pathwise\FileManager\FileOperations;
use Infocyph\Pathwise\FileManager\SafeFileWriter;
use Infocyph\Pathwise\Storage\StorageContext;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\PhpseclibV3\UnableToEstablishAuthenticityOfHost;

function sftpTrustWorkload(): void
{
    $pins = preg_split('/\s+/', trim((string) getenv('LAB_SFTP_HOST_KEYS')), -1, PREG_SPLIT_NO_EMPTY);
    verify(is_array($pins) && $pins !== [], 'Out-of-band SSH host fingerprints unavailable.');
    $password = (string) getenv('LAB_SFTP_SECRET');
    $untrusted = new SftpConnectionProvider('127.0.0.1', 'experiment', $password, port: 12222, timeout: 3, maxTries: 1, hostFingerprint: 'SHA256:' . base64_encode(random_bytes(32)));
    expectFailure($untrusted->provideConnection(...), UnableToEstablishAuthenticityOfHost::class, 'Wrong SSH host fingerprint accepted.');
    $trusted = new SftpConnectionProvider('127.0.0.1', 'experiment', $password, port: 12222, timeout: 5, hostFingerprint: $pins);
    $context = new StorageContext([
        'files' => ['driver' => 'sftp-v3', 'constructor' => [$trusted, '/upload/trusted']],
        'foreign' => ['driver' => 'sftp-v3', 'constructor' => [$trusted, '/upload/foreign']],
    ], 'files');
    remoteStorageWorkload($context);
    $disk = $context->filesystem();
    $disk->write('reconnect.txt', 'durable');
    $trusted->disconnect();
    verify($disk->read('reconnect.txt') === 'durable', 'Trusted replacement SSH session lost data.');
    $disk->delete('reconnect.txt');
    echo "Out-of-band SSH host trust, wrong pin rejection and reconnect passed\n";
}

function fileTransactionsWorkload(): void
{
    $root = evidenceDirectory() . '/file-transaction-fixture';
    verify(mkdir($root, 0700), 'Fixture directory unavailable.');
    $original = $root . '/original.txt';
    $renamed = $root . '/renamed.txt';
    $copy = $root . '/copy.txt';
    $file = new FileOperations($original);
    $file->create('original')->setPermissions(0600);
    (new FileOperations($copy))->create('previous-copy');
    expectFailure($file->commitTransaction(...), TransactionStateException::class, 'Commit without transaction accepted.');
    $file->beginTransaction();
    expectFailure($file->beginTransaction(...), TransactionStateException::class, 'Nested transaction accepted.');
    $file->update('changed')->copy($copy)->rename($renamed)->delete();
    $file->rollbackTransaction();
    verify($file->read() === 'original' && file_get_contents($copy) === 'previous-copy' && !file_exists($renamed), 'Rollback failed to restore original and overwritten destination.');
    clearstatcache(true, $original);
    verify((fileperms($original) & 0777) === 0600, 'Rollback changed original permissions.');
    $failure = new DomainException('Deliberate transaction failure.');
    try {
        $file->transaction(static function (FileOperations $active) use ($failure): never {
            $active->append('temporary')->setPermissions(0644);
            throw $failure;
        });
        throw new LogicException('Failed callback reported success.');
    } catch (DomainException $caught) {
        verify($caught === $failure, 'Rollback replaced original failure.');
    }
    verify($file->read() === 'original', 'Callback rollback failed.');
    $file->transaction(static fn(FileOperations $active) => $active->writeAndVerify('committed')->copyWithVerification($copy));
    verify($file->read() === 'committed' && file_get_contents($copy) === 'committed', 'Commit reconciliation failed.');
    $writer = new SafeFileWriter($original);
    $writer->enableAtomicWrite();
    $writer->writeLine('replacement');
    verify(file_get_contents($original) === 'committed', 'Atomic writer published before close.');
    $writer->close();
    verify(trim((string) file_get_contents($original)) === 'replacement', 'Atomic publication failed.');
    echo "Local transaction lifecycle, overwritten destination rollback, mode restoration, checksum and atomic publication passed\n";
}
