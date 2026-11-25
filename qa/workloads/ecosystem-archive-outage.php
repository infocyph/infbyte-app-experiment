<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\PathwiseException;
use Infocyph\Pathwise\FileManager\FileCompression;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\FilesystemException;

function archiveOutagePhase(string $phase, string $contract): void
{
    verify(in_array($contract, ['source', 'save', 'extract'], true), 'Unknown archive fault contract.');
    $root = evidenceDirectory() . '/archive-outage';
    $localZip = $root . '/stable.zip';
    $archive = 'archives/stable.zip';
    $source = 'source/receipt.bin';
    $published = 'archives/replacement.zip';
    $target = 'target/existing.bin';
    if ($phase === 'seed') {
        verify(mkdir($root, 0700), 'Unable to create archive evidence directory.');
        $payload = random_bytes(65536);
        verify(file_put_contents($root . '/source.bin', $payload) === strlen($payload), 'Local payload seed failed.');
        $zip = new ZipArchive();
        verify($zip->open($localZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true
            && $zip->addFromString('receipt.bin', $payload) && $zip->close(), 'Independent archive seed failed.');
        $context = storageOutageContext(true);
        $disk = $context->filesystem();
        $foreign = $context->filesystem('foreign');
        $disk->write($source, $payload);
        $disk->write($archive, (string) file_get_contents($localZip));
        $disk->write($target, 'existing');
        $foreign->write($source, 'foreign-source');
        $foreign->write($archive, 'foreign-archive');
        $foreign->write($published, 'foreign-publication');
        $foreign->write('target/receipt.bin', 'foreign-target');
        verify($disk->read($source) === $payload && !$disk->fileExists($published), 'Archive seed observation failed.');
        return;
    }

    $context = storageOutageContext(false);
    $disk = $context->filesystem();
    FlysystemHelper::mount('labarchivefault', $disk);
    try {
        if ($phase === 'hold') {
            // Establish a positive control before the service is stopped.
            verify(hash('sha256', $disk->read($source)) === hash_file('sha256', $root . '/source.bin'), 'Source control failed.');
            $zip = match ($contract) {
                'source' => new FileCompression($root . '/source-attempt.zip', true),
                'save' => new FileCompression('labarchivefault://' . $published, true),
                'extract' => new FileCompression('labarchivefault://' . $archive),
            };
            if ($contract === 'save') {
                $zip->addFile($root . '/source.bin', 'receipt.bin');
            } elseif ($contract === 'extract') {
                verify($zip->listFiles() === ['receipt.bin'], 'Prepared remote archive could not be read.');
            }
            verify(touch($root . '/prepared'), 'Fault barrier unavailable.');
            $deadline = hrtime(true) + 60_000_000_000;
            while (!is_file($root . '/offline')) {
                verify(hrtime(true) < $deadline, 'Timed out waiting for actual service stop.');
                usleep(20_000);
            }
            $rejected = false;
            try {
                match ($contract) {
                    'source' => $zip->addFile('labarchivefault://' . $source, 'receipt.bin'),
                    'save' => $zip->save(),
                    'extract' => $zip->decompress('labarchivefault://target'),
                };
            } catch (PathwiseException|FilesystemException|\MongoDB\Driver\Exception\Exception $failure) {
                $rejected = true;
                verify(file_put_contents($root . '/rejected', $failure::class) !== false, 'Rejection evidence unavailable.');
            }
            unset($zip); // Destroy while the service is still offline.
            verify($rejected, 'Archive operation reported success during a confirmed outage.');
            return;
        }

        verify($phase === 'recover' && is_file($root . '/rejected'), 'Fault evidence missing.');
        $foreign = $context->filesystem('foreign');
        $payload = (string) file_get_contents($root . '/source.bin');
        verify($disk->read($source) === $payload && $disk->read($archive) === file_get_contents($localZip), 'Confirmed source/archive changed.');
        verify(!$disk->fileExists($published), 'Unconfirmed archive publication appeared.');
        verify(archiveRemoteFiles($disk, 'target') === [$target => hash('sha256', 'existing')], 'Offline extraction altered target.');
        $retry = new FileCompression('labarchivefault://' . $published, true);
        $retry->addFile('labarchivefault://' . $source, 'receipt.bin')->save();
        unset($retry);
        $bytes = $disk->read($published);
        $nativePath = $root . '/verified.zip';
        verify(file_put_contents($nativePath, $bytes) === strlen($bytes), 'Recovered archive localization failed.');
        $native = new ZipArchive();
        verify($native->open($nativePath) === true && $native->numFiles === 1
            && $native->getFromName('receipt.bin') === $payload && $native->close(), 'Recovered archive failed independent ZIP validation.');
        new FileCompression('labarchivefault://' . $published)->decompress('labarchivefault://target')->save();
        verify(archiveRemoteFiles($disk, 'target') === [
            $target => hash('sha256', 'existing'),
            'target/receipt.bin' => hash('sha256', $payload),
        ], 'Recovered extraction manifest mismatch.');
        verify($disk->read($archive) === file_get_contents($localZip)
            && $foreign->read($source) === 'foreign-source'
            && $foreign->read($archive) === 'foreign-archive'
            && $foreign->read($published) === 'foreign-publication'
            && $foreign->read('target/receipt.bin') === 'foreign-target', 'Recovery crossed archive or tenant ownership.');
        echo "Actual archive $contract outage and independent recovery passed\n";
    } finally {
        FlysystemHelper::unmount('labarchivefault');
    }
}
