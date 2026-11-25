<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Filesystem\StorageRegistry;
use Infocyph\Foundation\Filesystem\StorageLinkManager;
use Infocyph\Foundation\Filesystem\FilesystemPublicFileResolver;
use Infocyph\Pathwise\Exceptions\DownloadException;
use Infocyph\Pathwise\Exceptions\PolicyViolationException;
use Infocyph\Pathwise\Exceptions\FileNotFoundException;

function foundationNamedCacheContracts(string $backend): void
{
    $root = evidenceDirectory() . '/named-provider-cache';
    verify(mkdir($root, 0700), 'Cache root unavailable.');
    $store = ['driver' => $backend === 'phpfiles' ? 'php_files' : $backend, 'fail_open' => false,
        'security' => ['integrity_key' => str_repeat('a', 32)], 'compression' => ['threshold_bytes' => 32]];
    $store += match ($backend) {
        'redis' => ['dsn' => 'redis://127.0.0.1:6379'],
        'valkey' => ['dsn' => 'valkey://127.0.0.1:6380'],
        'memcached' => ['servers' => [['127.0.0.1', 11211, 0]]],
        'file', 'phpfiles' => ['directory' => $root],
        'sqlite' => ['sqlite_file' => $root . '/data.sqlite'],
        'pdo' => ['connection' => 'main'],
        default => throw new InvalidArgumentException('Unknown named cache backend.'),
    };
    $overrides = ['cache' => ['default' => 'lab-main', 'stores' => [
        'lab-main' => $store + ['namespace' => 'lab-provider-main'],
        'lab-foreign' => $store + ['namespace' => 'lab-provider-foreign'],
    ]]];
    $app = foundationProviderApp($overrides, ['cache', 'database']);
    $payload = ['order' => 42, 'body' => str_repeat("abc\x00", 100)];
    $app->execution()->run(static function () use ($app, $payload): void {
        $manager = $app->make(CacheManager::class);
        $cache = $manager->store();
        verify($cache === $manager->store('lab-main') && $cache === $app->make(CacheInterface::class)
            && $cache === $app->make('foundation.cache') && $cache === $app->make(\Psr\SimpleCache\CacheInterface::class)
            && $cache === $app->make(\Psr\Cache\CacheItemPoolInterface::class), 'Configured default cache aliases diverged.');
        $foreign = $manager->store('lab-foreign');
        verify($foreign !== $cache && $cache->set('receipt', $payload, 600)
            && $foreign->set('receipt', 'foreign', 600), 'Named cache publication failed.');
        $native = $app->make(CacheLayerFactory::class)->make('lab-main');
        verify($native !== $cache && $native->get('receipt') === $payload, 'Fresh configured native store did not see data.');
        expectFailure(static fn() => $manager->store('lab-unknown-driver'), ConfigurationException::class, 'Unknown configured store accepted.');
        verify($cache->get('receipt') === $payload && $foreign->get('receipt') === 'foreign', 'Rejected store resolution damaged named stores.');
    });
    $replacement = foundationProviderApp($overrides, ['cache', 'database']);
    $replacement->execution()->run(static function () use ($replacement, $payload): void {
        $manager = $replacement->make(CacheManager::class);
        $cache = $manager->store();
        $foreign = $manager->store('lab-foreign');
        verify($cache->get('receipt') === $payload && $foreign->get('receipt') === 'foreign', 'Replacement application lost named store data.');
        verify($cache->clear() && !$cache->has('receipt') && $foreign->get('receipt') === 'foreign', 'Named clear crossed ownership.');
    });
    echo "Foundation named $backend configuration, alias ownership and replacement application passed\n";
}

function foundationCacheReplacementContracts(): void
{
    $root = evidenceDirectory() . '/cache-provider-lock';
    verify(mkdir($root, 0700), 'Lock root unavailable.');
    $app = foundationProviderApp(['cache' => [
        'default' => 'lab-memory', 'stores' => ['lab-memory' => ['driver' => 'memory', 'namespace' => 'lab-memory']],
        'lock' => ['driver' => 'file', 'directory' => $root, 'store' => 'lab-memory'],
    ]], ['cache']);
    $app->execution()->run(static function () use ($app): void {
        $manager = $app->make(CacheManager::class);
        $old = $manager->store();
        $lock = $manager->lock();
        verify($lock === $manager->lock('lab-memory'), 'Named lock identity diverged.');
        verify($old->set('key', 'old', 600), 'Old cache seed failed.');
        $replacement = Cache::memory('lab-replacement');
        verify($replacement->set('key', 'new', 600) && $manager->useStore($replacement) === $replacement, 'Explicit store replacement failed.');
        $next = $manager->lock();
        verify($manager->store() === $replacement && $next !== $lock, 'Replacement retained old named store/lock registry.');
        verify($old->get('key') === 'old' && $replacement->get('key') === 'new', 'Replacement mutated displaced store.');
        $held = $lock->acquire('report', 0.0, 30.0);
        verify($held !== null, 'Lock fixture acquisition failed.');
        try {
            verify($next->acquire('report', 0.0, 30.0) === null, 'Configured file lock replacement admitted concurrent owner.');
        } finally {
            $lock->release($held);
        }
        $held = $next->acquire('report', 1.0, 30.0);
        verify($held !== null, 'Replacement lock did not recover.');
        $next->release($held);
    });
    echo "Foundation store replacement invalidated lock registry and preserved resource ownership\n";
}

function foundationStorageProviderContracts(string $contract): void
{
    $root = evidenceDirectory() . '/provider-storage';
    foreach (['public', 'storage', 'other'] as $dir) {
        verify(mkdir($root . '/' . $dir, 0700, true), 'Storage root unavailable.');
    }
    $link = $root . '/public/shared';
    $target = $root . '/storage/shared';
    $app = foundationProviderApp([
        'paths' => ['public' => $root . '/public', 'storage' => $root . '/storage'],
        'filesystem' => [
            'default' => 'lab-primary',
            'disks' => [
                'lab-primary' => ['driver' => 'local', 'root' => $root . '/storage'],
                'lab-secondary' => ['driver' => 'local', 'root' => $root . '/other'],
            ],
            'links' => [$link => $target],
            'public_files' => ['root' => $root . '/public', 'symlink_policy' => $contract === 'public-allow' ? 'allow_within_root' : 'reject'],
        ],
    ], ['filesystem']);
    if ($contract === 'disks') {
        $registry = $app->make(StorageRegistry::class);
        verify($registry === $app->make('foundation.files') && $registry === $app->make('foundation.filesystem'), 'Storage aliases diverged.');
        verify($registry->defaultDisk() === 'lab-primary' && $registry->disk() === $registry->disk('lab-primary'), 'Default disk ownership differed.');
        $primary = $registry->disk();
        $secondary = $registry->disk('lab-secondary');
        $primary->write('order.bin', "primary\x00");
        $secondary->write('order.bin', 'secondary');
        verify($primary->read('order.bin') === "primary\x00" && $secondary->read('order.bin') === 'secondary', 'Named disks crossed ownership.');
        verify(file_get_contents($registry->localPath('order.bin')) === "primary\x00"
            && file_get_contents($registry->localPath('order.bin', 'lab-secondary')) === 'secondary', 'Disk paths did not resolve actual bytes.');
        $primary->delete('order.bin');
        verify(!$primary->fileExists('order.bin') && $secondary->fileExists('order.bin'), 'Disk delete crossed ownership.');
    } elseif (in_array($contract, ['link-lifecycle', 'link-rejection'], true)) {
        $manager = $app->make(StorageLinkManager::class);
        if ($contract === 'link-lifecycle') {
            verify($manager->create()[0]['created'] && is_link($link) && is_dir($target), 'Configured storage link not created.');
            verify(!$manager->create()[0]['created'] && $manager->status()[0]['matches'], 'Repeated creation was not idempotent.');
            verify(file_put_contents($link . '/record', 'retained') === 8 && file_get_contents($target . '/record') === 'retained', 'Link did not resolve actual storage.');
            verify($manager->remove()[0]['removed'] && !is_link($link) && file_get_contents($target . '/record') === 'retained', 'Link removal deleted target.');
            verify(!$manager->remove()[0]['removed'], 'Repeated removal was not idempotent.');
        } else {
            verify(file_put_contents($link, 'owned') === 5, 'Non-link fixture unavailable.');
            expectFailure($manager->create(...), PolicyViolationException::class, 'Existing file overwritten by link creation.');
            expectFailure($manager->remove(...), PolicyViolationException::class, 'Existing file removed by link operation.');
            verify(file_get_contents($link) === 'owned' && unlink($link), 'Rejected file mutation changed bytes.');
            verify(mkdir($target, 0700) && mkdir($root . '/storage/foreign', 0700)
                && symlink($root . '/storage/foreign', $link), 'Foreign link fixture failed.');
            expectFailure($manager->create(...), PolicyViolationException::class, 'Foreign link overwritten.');
            expectFailure($manager->remove(...), PolicyViolationException::class, 'Foreign link removed.');
            verify(is_link($link) && readlink($link) === $root . '/storage/foreign', 'Rejected link mutation changed owner.');
        }
    } elseif (in_array($contract, ['public-reject', 'public-allow'], true)) {
        $resolver = $app->make(FilesystemPublicFileResolver::class);
        $file = $root . '/public/report.txt';
        verify(file_put_contents($file, 'public report') === 13
            && file_put_contents($root . '/other/secret', 'private') === 7
            && symlink($file, $root . '/public/inside')
            && symlink($root . '/other/secret', $root . '/public/outside'), 'Public file fixture failed.');
        $result = $resolver->resolve('report.txt');
        verify($result->path === $file && $result->size === 13 && $result->relativePath === 'report.txt'
            && $result->mimeType === 'text/plain', 'Public metadata differed from native file.');
        expectFailure(static fn() => $resolver->resolve('../other/secret'), DownloadException::class, 'Traversal escaped configured root.');
        expectFailure(static fn() => $resolver->resolve('outside'), DownloadException::class, 'Outside symlink escaped configured root.');
        expectFailure(static fn() => $resolver->resolve('missing'), FileNotFoundException::class, 'Absent file was resolved.');
        if ($contract === 'public-allow') {
            verify($resolver->resolve('inside')->path === $file, 'Within-root link policy rejected valid target.');
        } else {
            expectFailure(static fn() => $resolver->resolve('inside'), DownloadException::class, 'Reject policy allowed symbolic link.');
        }
    } else {
        throw new InvalidArgumentException('Unknown storage provider contract.');
    }
    echo "Foundation configured storage $contract passed\n";
}
