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
