<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Filesystem\StorageRegistry;
use League\Flysystem\PathTraversalDetected;

final readonly class Storage
{
    public function __construct(private Application $app) {}

    public function setup(): void
    {
        $manager = $this->app->make(CacheManager::class);
        ensure($manager->store('shared')->set('storage-count', 0, 3600), 'Unable to initialize shared count.');
        $disk = $this->app->make(StorageRegistry::class)->disk('local');
        try {
            $disk->write('../experiment-forbidden.txt', 'forbidden');
            throw new \RuntimeException('Storage accepted parent traversal.');
        } catch (PathTraversalDetected) {
            // A native path-normalization rejection, not an arbitrary IO failure.
        }
    }

    public function execute(string $id): void
    {
        $disk = $this->app->make(StorageRegistry::class)->disk('local');
        $payload = str_repeat(hash('sha256', $id, true), 4096);
        $path = 'experiment/streams/' . $id . '.bin';
        $source = fopen('php://temp', 'w+b');
        ensure(is_resource($source), 'Unable to open stream.');
        try {
            ensure(fwrite($source, $payload) === strlen($payload), 'Short stream write.');
            rewind($source);
            $disk->writeStream($path, $source);
        } finally {
            fclose($source);
        }
        $target = $disk->readStream($path);
        try {
            $digest = hash_init('sha256');
            ensure(hash_update_stream($digest, $target) === strlen($payload), 'Short stream read.');
            ensure(hash_equals(hash('sha256', $payload), hash_final($digest)), 'Stream content mismatch.');
        } finally {
            fclose($target);
        }
        $disk->move($path, $path . '.complete');
        ensure(!$disk->fileExists($path) && $disk->fileExists($path . '.complete'), 'File move state mismatch.');
        $manager = $this->app->make(CacheManager::class);
        $cache = $manager->store('shared');
        $atomic = $cache->atomic();
        ensure($atomic !== null, 'Missing atomic cache.');
        ensure($atomic->setIfAbsent('stream.' . $id, 'pending', 3600), 'Duplicate stream.');
        ensure($atomic->compareAndSet('stream.' . $id, 'pending', 'complete', 3600), 'Atomic completion failed.');
        ensure(!$atomic->compareAndSet('stream.' . $id, 'pending', 'duplicate'), 'Stale compare-and-set accepted.');
        ensure($atomic->getAndDelete('stream.' . $id) === 'complete', 'Atomic consumption failed.');
        ensure($atomic->getAndDelete('stream.' . $id) === null, 'Consumed cache value replayed.');
        $lock = $manager->lock('shared');
        $handle = $lock->acquire('storage-ledger', 10.0, 20.0);
        ensure($handle !== null, 'Unable to acquire distributed lock.');
        try {
            ensure($cache->set('storage-count', countValue($cache->get('storage-count')) + 1, 3600), 'Ledger write failed.');
        } finally {
            $lock->release($handle);
        }
    }

    public function verify(int $expected): void
    {
        $cache = $this->app->make(CacheManager::class)->store('shared');
        ensure(countValue($cache->get('storage-count')) === $expected, 'Lost distributed ledger increments.');
        $disk = $this->app->make(StorageRegistry::class)->disk('local');
        $count = 0;
        foreach ($disk->listContents('experiment/streams') as $entry) {
            ensure($entry->isFile() && str_ends_with($entry->path(), '.complete'), 'Uncommitted stream.');
            ensure($disk->fileSize($entry->path()) === 131072, 'Incorrect persisted stream size.');
            ++$count;
        }
        ensure($count === $expected, 'File count mismatch.');
        ensure($cache->set('storage-expiring', 'short-lived', 1), 'TTL fixture write failed.');
        sleep(2);
        ensure(!$cache->has('storage-expiring'), 'Expired value remains visible.');
    }
}
