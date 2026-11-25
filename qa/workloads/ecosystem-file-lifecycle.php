<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\UnsafeArchiveEntryException;
use Infocyph\Pathwise\FileManager\FileCompression;
use Infocyph\Pathwise\Indexing\ChecksumIndexer;
use Infocyph\Pathwise\Results\SnapshotDiff;
use Infocyph\Pathwise\Retention\RetentionManager;
use Infocyph\Pathwise\Utils\FileWatcher;

function fileIndexRetentionWorkload(): void
{
    $root = evidenceDirectory() . '/index-retention';
    verify(mkdir($root, 0700), 'Fixture directory creation failed.');
    $now = time();
    $expected = [];
    for ($i = 0; $i < 120; ++$i) {
        $path = $root . '/file-' . $i . '.txt';
        $content = str_repeat((string) ($i % 30) . ':', 64);
        verify(file_put_contents($path, $content) === strlen($content), 'Fixture write failed.');
        verify(touch($path, $now - $i * 86400), 'Fixture timestamp failed.');
        $expected[hash('sha256', $content)][] = $path;
    }
    ksort($expected);
    $index = ChecksumIndexer::buildIndex($root);
    foreach ($index as &$paths) {
        sort($paths);
    }
    unset($paths);
    foreach ($expected as &$paths) {
        sort($paths);
    }
    unset($paths);
    verify($index === $expected && count(ChecksumIndexer::findDuplicates($root)) === 30, 'Checksum groups differ from independent oracle.');
    $count = 0;
    foreach (ChecksumIndexer::iterate($root) as $entry) {
        verify(hash_file('sha256', $entry['path']) === $entry['checksum'], 'Streamed checksum mismatch.');
        ++$count;
    }
    verify($count === 120, 'Streamed index lost records.');
    $preview = RetentionManager::preview($root, keepLast: 20);
    verify(count($preview->kept) === 20 && count($preview->deleted) === 100 && count(glob($root . '/*.txt')) === 120, 'Retention preview mutated files or selected wrong count.');
    $applied = RetentionManager::apply($root, keepLast: 20);
    verify($applied->deleted === $preview->deleted && $applied->kept === $preview->kept, 'Retention decision changed between preview and apply.');
    for ($i = 0; $i < 120; ++$i) {
        verify(is_file($root . '/file-' . $i . '.txt') === ($i < 20), 'Wrong retention target.');
    }
    $duplicates = $root . '/duplicates';
    verify(mkdir($duplicates, 0700), 'Duplicate fixture directory unavailable.');
    foreach (['a', 'b', 'c'] as $name) {
        verify(file_put_contents($duplicates . '/' . $name, 'identical') === 9, 'Duplicate fixture write failed.');
    }
    $result = ChecksumIndexer::deduplicateWithHardLinks($duplicates);
    clearstatcache(true);
    verify(count($result->linked) === 2 && $result->skipped === [], 'Deduplication did not link two duplicates.');
    verify(fileinode($duplicates . '/a') === fileinode($duplicates . '/b') && fileinode($duplicates . '/b') === fileinode($duplicates . '/c'), 'Files are not actual hard links.');
    verify(file_get_contents($duplicates . '/c') === 'identical', 'Deduplication changed bytes.');
    expectFailure(static fn() => RetentionManager::preview($root, keepLast: -1), InvalidArgumentException::class, 'Invalid retention policy accepted.');
    echo "120-file index, streaming checksums, exact retention preview/apply and real hard-link deduplication passed\n";
}

function archiveSecurityWorkload(): void
{
    $root = evidenceDirectory() . '/archive-boundaries';
    verify(mkdir($root, 0700), 'Archive fixture directory failed.');
    $destination = $root . '/extract';
    verify(mkdir($destination, 0700), 'Destination unavailable.');
    verify(file_put_contents($destination . '/existing.txt', 'original') === 8, 'Existing data fixture failed.');
    $create = static function (string $path, array $entries): void {
        $zip = new ZipArchive();
        verify($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Cannot create ZIP fixture.');
        foreach ($entries as $name => $data) {
            verify($zip->addFromString($name, $data), 'Cannot add ZIP fixture entry.');
        }
        verify($zip->close(), 'ZIP fixture close failed.');
    };
    foreach (['../escaped.txt', '/absolute.txt', 'nested/../../escaped.txt'] as $index => $invalid) {
        $path = $root . '/invalid-' . $index . '.zip';
        $create($path, ['existing.txt' => 'changed', $invalid => 'fixture']);
        expectFailure(static fn() => new FileCompression($path)->decompress($destination), UnsafeArchiveEntryException::class, 'Unsafe archive path accepted.');
        verify(file_get_contents($destination . '/existing.txt') === 'original' && !is_file($root . '/escaped.txt'), 'Unsafe archive mutated destination.');
    }
    $path = $root . '/bounded.zip';
    $create($path, ['existing.txt' => 'changed', 'large.txt' => str_repeat('x', 4096)]);
    expectFailure(static fn() => new FileCompression($path)->setExtractionLimits(maxEntryUncompressedBytes: 1024)->decompress($destination), UnsafeArchiveEntryException::class, 'Oversized ZIP entry accepted.');
    verify(file_get_contents($destination . '/existing.txt') === 'original' && !file_exists($destination . '/large.txt'), 'Rejected archive partially extracted.');
    $path = $root . '/valid.zip';
    $create($path, ['existing.txt' => 'replacement', 'nested/file.txt' => 'valid']);
    new FileCompression($path)->decompress($destination);
    verify(file_get_contents($destination . '/existing.txt') === 'replacement' && file_get_contents($destination . '/nested/file.txt') === 'valid', 'Valid archive did not reconcile.');
    echo "ZIP traversal, expansion bounds, no partial mutation and valid extraction passed\n";
}

function fileWatchWorkload(): void
{
    $root = evidenceDirectory() . '/watch-files';
    verify(mkdir($root, 0700), 'Watcher fixture directory failed.');
    verify(file_put_contents($root . '/modify', 'before') === 6, 'Watcher seed failed.');
    verify(file_put_contents($root . '/delete', 'before') === 6, 'Watcher seed failed.');
    $before = FileWatcher::snapshot($root);
    $pid = pcntl_fork();
    verify($pid >= 0, 'Watcher fixture process failed.');
    if ($pid === 0) {
        usleep(700000);
        $ok = file_put_contents($root . '/modify', 'changed-size') === 12
            && file_put_contents($root . '/create', 'new') === 3
            && unlink($root . '/delete');
        exit($ok ? 0 : 1);
    }
    $created = [];
    $modified = [];
    $deleted = [];
    try {
        FileWatcher::watch($root, static function (SnapshotDiff $diff) use (&$created, &$modified, &$deleted): void {
            array_push($created, ...$diff->created);
            array_push($modified, ...$diff->modified);
            array_push($deleted, ...$diff->deleted);
        }, durationSeconds: 3, intervalMilliseconds: 50);
    } finally {
        pcntl_waitpid($pid, $status);
    }
    verify(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Mutation worker failed.');
    $expected = FileWatcher::diff($before, FileWatcher::snapshot($root));
    verify(array_values(array_unique($created)) === [$root . '/create'] && array_values(array_unique($modified)) === [$root . '/modify'] && array_values(array_unique($deleted)) === [$root . '/delete'], 'Live watcher missed or invented changes.');
    verify($expected->created === $created && $expected->modified === $modified && $expected->deleted === $deleted, 'Watcher and final snapshot mismatch.');
    echo "Independent process create/modify/delete, live polling and final snapshot reconciliation passed\n";
}

function archiveContractsWorkload(): void
{
    $root = evidenceDirectory() . '/archive-contracts';
    foreach ([$root, $root . '/source', $root . '/selected', $root . '/wrong', $root . '/correct', $root . '/bounded'] as $path) {
        verify(mkdir($path, 0700), 'Archive directory creation failed.');
    }
    $source = $root . '/source';
    $files = ['a.txt' => str_repeat('alpha', 100), 'b.txt' => str_repeat('beta', 100), 'keep.log' => 'audit', 'skip.tmp' => 'excluded'];
    foreach ($files as $name => $content) {
        verify(file_put_contents($source . '/' . $name, $content) === strlen($content), 'Archive source write failed.');
    }
    $events = [];
    $archive = new FileCompression($root . '/filtered.zip', true);
    $archive->setGlobPatterns(['*.txt', '*.log'], ['*.tmp'])
        ->setProgressCallback(static function (array $event) use (&$events): void { $events[] = $event; })
        ->compress($source)->save();
    $names = $archive->listFiles();
    sort($names);
    verify($names === ['a.txt', 'b.txt', 'keep.log'] && $archive->fileCount() === 3, 'Filtered archive membership differs.');
    $iterated = array_values(iterator_to_array($archive->getFileIterator()));
    sort($iterated);
    verify($iterated === $names, 'Archive iterator disagrees with manifest.');
    $archive->batchExtractFiles(['a.txt' => 'renamed.txt', 'keep.log' => 'audit.log'], $root . '/selected');
    verify(file_get_contents($root . '/selected/renamed.txt') === $files['a.txt']
        && file_get_contents($root . '/selected/audit.log') === $files['keep.log']
        && count(glob($root . '/selected/*')) === 2, 'Selected extraction lost or invented entries.');
    verify(in_array('compress', array_column($events, 'operation'), true)
        && in_array('decompress', array_column($events, 'operation'), true), 'Progress events missing.');

    $password = bin2hex(random_bytes(16));
    $encrypted = $root . '/encrypted.zip';
    new FileCompression($encrypted, true)->setPassword($password)
        ->batchAddFiles([$source . '/a.txt' => 'a.txt', $source . '/b.txt' => 'b.txt'])->save();
    $native = new ZipArchive();
    verify($native->open($encrypted) === true, 'Native ZIP inspection failed.');
    verify($native->getFromName('a.txt') === false, 'Encrypted entry readable without password.');
    verify($native->setPassword($password) && $native->getFromName('a.txt') === $files['a.txt'], 'Independent ZIP decryption mismatch.');
    verify($native->close(), 'ZIP inspection close failed.');
    expectFailure(static fn() => new FileCompression($encrypted)->setPassword('wrong-password')->decompress($root . '/wrong'),
        \Infocyph\Pathwise\Exceptions\CompressionException::class, 'Wrong archive password accepted.');
    new FileCompression($encrypted)->setPassword($password)->setDefaultDecompressionPath($root . '/correct')->decompress();
    foreach (['a.txt', 'b.txt'] as $name) {
        verify(file_get_contents($root . '/correct/' . $name) === $files[$name], 'Encrypted extraction bytes differ.');
    }
    $bounded = $root . '/bounded';
    verify(file_put_contents($bounded . '/sentinel', 'original') === 8, 'Bounded extraction seed failed.');
    foreach ([
        static fn(FileCompression $zip) => $zip->setExtractionLimits(maxEntries: 2),
        static fn(FileCompression $zip) => $zip->setExtractionLimits(maxTotalUncompressedBytes: 600),
        static fn(FileCompression $zip) => $zip->setExtractionLimits(maxCompressionRatio: 2.0),
    ] as $limit) {
        expectFailure(static fn() => $limit(new FileCompression($root . '/filtered.zip'))->decompress($bounded),
            UnsafeArchiveEntryException::class, 'ZIP limit ignored.');
        verify(glob($bounded . '/*') === [$bounded . '/sentinel'] && file_get_contents($bounded . '/sentinel') === 'original', 'Rejected archive changed destination.');
    }
    echo "Filtered archive membership, selected extraction, progress, encrypted native interop and resource limits passed\n";
}

function archiveLateRollbackWorkload(): void
{
    $root = evidenceDirectory() . '/archive-late-rollback';
    $destination = $root . '/extract';
    verify(mkdir($destination, 0700, true), 'Extraction fixture directory failed.');
    verify(file_put_contents($destination . '/existing.txt', 'original') === 8 && chmod($destination . '/existing.txt', 0640), 'Original fixture write failed.');
    verify(file_put_contents($destination . '/conflict', 'owned') === 5, 'Conflict fixture write failed.');
    $archive = $root . '/late.zip';
    $zip = new ZipArchive();
    verify($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'ZIP fixture unavailable.');
    foreach (['existing.txt' => 'replacement', 'created/first.txt' => 'new', 'conflict/late.txt' => 'blocked'] as $name => $payload) {
        verify($zip->addFromString($name, $payload), 'ZIP entry creation failed.');
    }
    verify($zip->close(), 'ZIP fixture close failed.');
    verify($zip->open($archive) === true, 'ZIP manifest unavailable.');
    $entries = \Infocyph\Pathwise\Security\ZipEntryValidator::validateArchive($zip, $destination);
    verify(count($entries) === 3 && $zip->close(), 'Valid manifest was rejected before extraction.');
    $compression = new FileCompression($archive)->setExecutionStrategy(\Infocyph\Pathwise\Core\ExecutionStrategy::PHP);
    expectFailure(static fn() => $compression->decompress($destination), UnsafeArchiveEntryException::class, 'Late file/directory conflict was accepted.');
    clearstatcache(true);
    verify(file_get_contents($destination . '/existing.txt') === 'original' && (fileperms($destination . '/existing.txt') & 0777) === 0640, 'Rollback did not restore original bytes or permissions.');
    verify(file_get_contents($destination . '/conflict') === 'owned' && !file_exists($destination . '/created'), 'Rollback did not remove created files/directories or preserve conflict.');
    $visible = array_values(array_diff(scandir($destination), ['.', '..']));
    sort($visible);
    verify($visible === ['conflict', 'existing.txt'], 'Extraction left staging files.');
    verify(unlink($destination . '/conflict'), 'Conflict removal failed.');
    $compression->decompress($destination);
    verify(file_get_contents($destination . '/existing.txt') === 'replacement' && file_get_contents($destination . '/created/first.txt') === 'new' && file_get_contents($destination . '/conflict/late.txt') === 'blocked', 'Same archive failed after conflict repair.');
    echo "Validated manifest, late extraction conflict, rollback of overwrites/new paths and successful retry passed\n";
}

function safeFileBinaryContracts(): void
{
    $path = evidenceDirectory() . '/binary-record';
    $data = str_repeat("abc\0\xff", 2049);
    $writer = new \Infocyph\Pathwise\FileManager\SafeFileWriter($path);
    verify($writer->writeBinary($data) === strlen($data), 'Binary write length differs.');
    $writer->close();
    $reader = new \Infocyph\Pathwise\FileManager\SafeFileReader($path, 'rb');
    foreach ([1, 127, 4096] as $size) {
        $hash = hash_init('sha256');
        $bytes = 0;
        $chunks = 0;
        foreach ($reader->chunks($size) as $chunk) {
            verify(strlen($chunk) <= $size, 'Chunk exceeded configured size.');
            $bytes += strlen($chunk);
            hash_update($hash, $chunk);
            ++$chunks;
        }
        verify($bytes === strlen($data) && hash_final($hash) === hash('sha256', $data) && $reader->count() === $chunks, 'Chunk replay lost or duplicated bytes.');
    }
    expectFailure(static fn() => $reader->chunks(0), InvalidArgumentException::class, 'Zero chunk size accepted.');
    echo "Binary writer and repeatable bounded reader digest reconciliation passed\n";
}

function safeFileCsvContracts(): void
{
    $path = evidenceDirectory() . '/records.csv';
    $rows = [['id', 'label'], ['1', 'comma, quote "'], ['2', "line\nbreak"], ['3', '0']];
    $writer = new \Infocyph\Pathwise\FileManager\SafeFileWriter($path);
    foreach ($rows as $row) {
        verify($writer->writeCsv($row, escape: '') > 0, 'CSV write failed.');
    }
    $writer->close();
    $reader = new \Infocyph\Pathwise\FileManager\SafeFileReader($path);
    $actual = iterator_to_array($reader->csv(escape: ''));
    verify($actual === $rows && $reader->count() === count($rows), 'CSV quoted/multiline record mismatch.');
    $native = fopen($path, 'rb');
    verify(is_resource($native), 'Independent CSV reader unavailable.');
    try {
        foreach ($rows as $row) {
            verify(fgetcsv($native, escape: '') === $row, 'Native CSV reconciliation differs.');
        }
        verify(fgetcsv($native, escape: '') === false, 'Native reader found extra row.');
    } finally {
        fclose($native);
    }
    echo "CSV quote, delimiter and multiline records independently reconciled\n";
}

function safeFileJsonContracts(): void
{
    $path = evidenceDirectory() . '/records.jsonl';
    $values = [null, false, 0, ['id' => 1, 'label' => "line\nbreak"]];
    $writer = new \Infocyph\Pathwise\FileManager\SafeFileWriter($path);
    foreach ($values as $value) {
        $writer->writeLine(json_encode($value, JSON_THROW_ON_ERROR));
    }
    $writer->close();
    $reader = new \Infocyph\Pathwise\FileManager\SafeFileReader($path);
    verify(iterator_to_array($reader->jsonLines()) === $values && $reader->count() === 4, 'JSONL falsey or escaped record differs.');
    file_put_contents($path, "invalid\n", FILE_APPEND | LOCK_EX);
    $fresh = new \Infocyph\Pathwise\FileManager\SafeFileReader($path);
    $seen = [];
    try {
        foreach ($fresh->jsonLines() as $value) {
            $seen[] = $value;
        }
        throw new LogicException('Malformed JSON record was accepted.');
    } catch (\Infocyph\Pathwise\Exceptions\FileAccessException) {
        verify($seen === $values, 'Malformed tail changed valid preceding records.');
    }
    echo "JSONL scalar/array preservation and malformed-tail failure passed\n";
}

function safeFileSerializedContracts(): void
{
    $path = evidenceDirectory() . '/serialized-records';
    $values = [false, null, 0, ['id' => 1]];
    $writer = new \Infocyph\Pathwise\FileManager\SafeFileWriter($path);
    foreach ($values as $value) {
        $writer->writeSerialized($value);
    }
    $writer->close();
    $reader = new \Infocyph\Pathwise\FileManager\SafeFileReader($path);
    verify(iterator_to_array($reader->serializedValues()) === $values, 'Safe serialization lost falsey values.');
    file_put_contents($path, serialize(new stdClass()) . "\n", FILE_APPEND | LOCK_EX);
    $fresh = new \Infocyph\Pathwise\FileManager\SafeFileReader($path);
    expectFailure(static fn() => iterator_to_array($fresh->serializedValues()), \Infocyph\Pathwise\Exceptions\FileAccessException::class, 'Serialized object was accepted.');
    echo "Serialized scalar/array roundtrip and object rejection passed\n";
}

function safeFileLockContracts(): void
{
    $path = evidenceDirectory() . '/locked-reader';
    verify(file_put_contents($path, "record\n") === 7, 'Lock fixture seed failed.');
    $reader = new \Infocyph\Pathwise\FileManager\SafeFileReader($path, lockType: LOCK_SH);
    verify(iterator_to_array($reader->lines()) === ["record\n"], 'Locked read differs.');
    $probe = fopen($path, 'r+b');
    verify(is_resource($probe), 'Independent file handle unavailable.');
    try {
        verify(!flock($probe, LOCK_EX | LOCK_NB), 'Exclusive ownership overlapped shared reader.');
        $reader->releaseLock();
        verify(flock($probe, LOCK_EX | LOCK_NB), 'Explicit reader release leaked lock.');
        flock($probe, LOCK_UN);
        unset($reader);
        $replacement = new \Infocyph\Pathwise\FileManager\SafeFileReader($path, lockType: LOCK_EX);
        verify(iterator_to_array($replacement->lines()) === ["record\n"], 'Replacement reader failed.');
        verify(!flock($probe, LOCK_SH | LOCK_NB), 'Shared lock overlapped exclusive reader.');
        unset($replacement);
        verify(flock($probe, LOCK_EX | LOCK_NB), 'Reader disposal leaked exclusive lock.');
        flock($probe, LOCK_UN);
    } finally {
        fclose($probe);
    }
    echo "Independent file handle lock contention, release and disposal passed\n";
}

function archiveGranularWorkload(string $variant): void
{
    $root = evidenceDirectory() . '/archive-' . $variant;
    $source = $root . '/source';
    $destination = $root . '/extract';
    verify(mkdir($source, 0700, true) && mkdir($destination, 0700), 'Archive fixture creation failed.');
    $files = ['a.txt' => str_repeat('a', 64), 'b.bin' => random_bytes(96)];
    foreach ($files as $name => $bytes) {
        verify(file_put_contents($source . '/' . $name, $bytes) === strlen($bytes), 'Archive fixture write failed.');
    }
    $path = $root . '/archive.zip';
    $compression = new FileCompression($path, true);
    if ($variant === 'extensions') {
        verify(mkdir($source . '/nested', 0700), 'Nested source creation failed.');
        verify(file_put_contents($source . '/nested/c.txt', 'nested') === 6, 'Nested source write failed.');
        $compression->compressWithFilter($source, ['txt'])->save();
        $names = array_values(array_filter($compression->listFiles(), static fn(string $name): bool => !str_ends_with($name, '/')));
        sort($names);
        verify($names === ['source/a.txt', 'source/nested/c.txt'], 'Extension filter lost or invented file entries.');
        $compression->decompress($destination);
        verify(file_get_contents($destination . '/source/a.txt') === $files['a.txt']
            && file_get_contents($destination . '/source/nested/c.txt') === 'nested'
            && !file_exists($destination . '/source/b.bin'), 'Filtered nested extraction differs.');
        return;
    }
    if ($variant === 'hooks') {
        $events = [];
        foreach (['beforeAdd', 'afterAdd', 'beforeSave', 'afterSave'] as $event) {
            $compression->registerHook($event, static function (...$arguments) use (&$events, $event): void {
                $events[] = [$event, ...$arguments];
            });
        }
        $compression->addFile($source . '/a.txt', 'renamed.txt')->save()->save();
        verify($events === [
            ['beforeAdd', $source . '/a.txt', 'renamed.txt'],
            ['afterAdd', $source . '/a.txt', 'renamed.txt'],
            ['beforeSave', $path],
            ['afterSave', $path],
        ], 'Archive hooks lost order, arguments or idempotent save semantics.');
        $native = new ZipArchive();
        verify($native->open($path) === true && $native->getFromName('renamed.txt') === $files['a.txt'] && $native->close(), 'Hook-observed archive not independently readable.');
        return;
    }
    if ($variant === 'aes128') {
        $password = bin2hex(random_bytes(24));
        expectFailure(static fn() => $compression->setPassword(''), \Infocyph\Pathwise\Exceptions\CompressionException::class, 'Empty password accepted.');
        expectFailure(static fn() => $compression->setEncryptionAlgorithm(ZipArchive::EM_NONE), \Infocyph\Pathwise\Exceptions\CompressionException::class, 'Unencrypted algorithm accepted as encryption.');
        $compression->setPassword($password)->setEncryptionAlgorithm(ZipArchive::EM_AES_128)
            ->addFile($source . '/b.bin', 'binary')->save();
        $native = new ZipArchive();
        verify($native->open($path) === true, 'Native AES-128 archive open failed.');
        $stat = $native->statName('binary');
        verify(is_array($stat) && $stat['encryption_method'] === ZipArchive::EM_AES_128, 'Archive encryption algorithm differs.');
        verify($native->getFromName('binary') === false, 'Encrypted entry readable without password.');
        verify($native->setPassword($password) && $native->getFromName('binary') === $files['b.bin'] && $native->close(), 'Native AES-128 decryption differs.');
        new FileCompression($path)->setPassword($password)->decompress($destination);
        verify(file_get_contents($destination . '/binary') === $files['b.bin'], 'Pathwise AES-128 extraction differs.');
        return;
    }
    $compression->batchAddFiles([$source . '/a.txt' => 'a.txt', $source . '/b.bin' => 'b.bin'])->save();
    verify(file_put_contents($destination . '/sentinel', 'original') === 8, 'Destination sentinel failed.');
    if ($variant === 'selection') {
        foreach ([
            ['a.txt' => 'new.txt', 'b.bin' => 'NEW.TXT'],
            ['a.txt' => 'new.txt', 'missing.txt' => 'other.txt'],
        ] as $mapping) {
            expectFailure(static fn() => $compression->batchExtractFiles($mapping, $destination), \Infocyph\Pathwise\Exceptions\CompressionException::class, 'Invalid selection accepted.');
            verify(scandir($destination) === ['.', '..', 'sentinel'], 'Invalid selection partially published files.');
        }
        expectFailure(static fn() => $compression->batchExtractFiles(['a.txt' => 'new.txt', 'b.bin' => '../escaped'], $destination), UnsafeArchiveEntryException::class, 'Unsafe selected destination accepted.');
        verify(scandir($destination) === ['.', '..', 'sentinel'] && !file_exists($root . '/escaped')
            && file_get_contents($destination . '/sentinel') === 'original', 'Rejected selection mutated destination.');
        $compression->batchExtractFiles(['b.bin' => 'renamed.bin'], $destination);
        verify(file_get_contents($destination . '/renamed.bin') === $files['b.bin'] && !file_exists($destination . '/a.txt'), 'Valid selection retry differs.');
        return;
    }
    if ($variant === 'limits') {
        expectFailure(static fn() => $compression->setExtractionLimits(maxEntries: -1), InvalidArgumentException::class, 'Negative limit accepted.');
        expectFailure(static fn() => $compression->setExtractionLimits(maxCompressionRatio: INF), InvalidArgumentException::class, 'Infinite ratio accepted.');
        expectFailure(static fn() => $compression->setExtractionLimits(maxEntryUncompressedBytes: 95)->decompress($destination), UnsafeArchiveEntryException::class, 'One-byte limit excess accepted.');
        verify(scandir($destination) === ['.', '..', 'sentinel'], 'Rejected boundary extraction published files.');
        $compression->setExtractionLimits(maxEntries: 2, maxEntryUncompressedBytes: 96, maxTotalUncompressedBytes: 160, maxCompressionRatio: 0)->decompress($destination);
        foreach ($files as $name => $bytes) {
            verify(file_get_contents($destination . '/' . $name) === $bytes, 'Exact-limit extraction differs.');
        }
        verify(file_get_contents($destination . '/sentinel') === 'original', 'Exact-limit extraction changed unrelated data.');
        return;
    }
    throw new InvalidArgumentException('Unknown archive variant.');
}
