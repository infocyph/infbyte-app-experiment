<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\CompressionException;
use Infocyph\Pathwise\Exceptions\UnsafeArchiveEntryException;
use Infocyph\Pathwise\FileManager\FileCompression;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\FilesystemOperator;

function archiveRemoteFiles(FilesystemOperator $disk, string $prefix): array
{
    $files = [];
    foreach ($disk->listContents($prefix, true) as $entry) {
        if ($entry->isFile()) {
            $files[$entry->path()] = hash('sha256', $disk->read($entry->path()));
        }
    }
    ksort($files);

    return $files;
}

function remoteArchiveContracts(string $backend, string $contract): void
{
    putenv('LAB_STORAGE_BACKEND=' . $backend);
    $context = storageOutageContext(true);
    $disk = $context->filesystem();
    $foreign = $context->filesystem('foreign');
    $peer = storageOutageContext(false)->filesystem();
    $root = evidenceDirectory() . '/remote-archive';
    verify(mkdir($root, 0700), 'Archive evidence directory unavailable.');
    $payloads = ['alpha.bin' => random_bytes(4096), 'nested/beta.bin' => random_bytes(3072)];
    foreach ($payloads as $name => $payload) {
        $disk->write('source/' . $name, $payload);
        $foreign->write('source/' . $name, 'foreign:' . $name);
    }
    $foreign->write('archives/receipt.zip', 'foreign-archive');
    $foreign->write('target/alpha.bin', 'foreign-target');
    FlysystemHelper::mount('labremote', $disk);
    try {
        $archive = 'labremote://archives/receipt.zip';
        if ($contract === 'traversal') {
            $native = new ZipArchive();
            $path = $root . '/unsafe.zip';
            verify($native->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Unsafe fixture archive unavailable.');
            verify($native->addFromString('alpha.bin', $payloads['alpha.bin'])
                && $native->addFromString('../escape.bin', 'forbidden')
                && $native->close(), 'Unsafe fixture construction failed.');
            $disk->write('archives/receipt.zip', (string) file_get_contents($path));
            $disk->write('target/existing.bin', 'previous');
            $before = archiveRemoteFiles($peer, 'target');
            expectFailure(static fn() => new FileCompression($archive)->decompress('labremote://target'),
                UnsafeArchiveEntryException::class, 'Remote traversal archive accepted.');
            verify(archiveRemoteFiles($peer, 'target') === $before && !$peer->fileExists('escape.bin'),
                'Unsafe remote archive published any new data.');
        } else {
            $password = $contract === 'encrypted' ? bin2hex(random_bytes(24)) : null;
            $writer = new FileCompression($archive, true);
            if ($password !== null) {
                $writer->setPassword($password)->setEncryptionAlgorithm(ZipArchive::EM_AES_256);
            }
            foreach ($payloads as $name => $_) {
                $writer->addFile('labremote://source/' . $name, $name);
            }
            $writer->save();
            unset($writer);
            $bytes = $peer->read('archives/receipt.zip');
            $path = $root . '/independent.zip';
            verify(file_put_contents($path, $bytes) === strlen($bytes), 'Independent ZIP copy failed.');
            $native = new ZipArchive();
            verify($native->open($path) === true && $native->numFiles === count($payloads), 'Remote archive was not a complete ZIP.');
            if ($password !== null) {
                verify($native->getFromName('alpha.bin') === false && $native->setPassword($password),
                    'Encrypted remote archive did not enforce a password.');
            }
            foreach ($payloads as $name => $payload) {
                verify($native->getFromName($name) === $payload, 'Independent native ZIP digest mismatch.');
            }
            verify($native->close(), 'Independent archive close failed.');
            unlink($path);
            if ($contract === 'collision') {
                $disk->write('target/nested/beta.bin', 'previous');
                $before = archiveRemoteFiles($peer, 'target');
                expectFailure(static fn() => new FileCompression($archive)->decompress('labremote://target'),
                    CompressionException::class, 'Remote extraction overwrote prior object.');
                verify(archiveRemoteFiles($peer, 'target') === $before && !$peer->fileExists('target/alpha.bin'),
                    'Rejected remote extraction partially published files.');
                $disk->delete('target/nested/beta.bin');
            } elseif ($contract === 'encrypted') {
                $disk->write('target/existing.bin', 'previous');
                $before = archiveRemoteFiles($peer, 'target');
                expectFailure(static fn() => new FileCompression($archive)->setPassword('wrong-password')->decompress('labremote://target'),
                    CompressionException::class, 'Wrong password accepted for remote extraction.');
                verify(archiveRemoteFiles($peer, 'target') === $before, 'Wrong password published partial remote data.');
                $disk->delete('target/existing.bin');
            }
            $reader = new FileCompression($archive);
            if ($password !== null) {
                $reader->setPassword($password);
            }
            if ($contract === 'selection') {
                $reader->batchExtractFiles(['nested/beta.bin' => 'renamed.bin'], 'labremote://target');
                $expected = ['target/renamed.bin' => hash('sha256', $payloads['nested/beta.bin'])];
            } else {
                $reader->decompress('labremote://target');
                $expected = [];
                foreach ($payloads as $name => $payload) {
                    $expected['target/' . $name] = hash('sha256', $payload);
                }
            }
            $reader->save();
            unset($reader);
            ksort($expected);
            verify(archiveRemoteFiles($peer, 'target') === $expected, 'Published remote extraction differs from independent expected manifest.');
            verify(hash('sha256', $peer->read('archives/receipt.zip')) === hash('sha256', $bytes),
                'Read/extract changed the remote archive.');
        }
        foreach ($payloads as $name => $payload) {
            verify($peer->read('source/' . $name) === $payload && $foreign->read('source/' . $name) === 'foreign:' . $name,
                'Archive operation modified source or crossed tenant namespace.');
        }
        verify($foreign->read('archives/receipt.zip') === 'foreign-archive' && $foreign->read('target/alpha.bin') === 'foreign-target',
            'Archive publication crossed tenant namespace.');
    } finally {
        FlysystemHelper::unmount('labremote');
    }
    echo "Remote archive $backend $contract passed\n";
}
