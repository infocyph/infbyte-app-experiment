<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cluster\ClusterCache;
use Infocyph\CacheLayer\Cluster\ClusterCacheConfig;
use Infocyph\CacheLayer\Cluster\ClusterRuntime;
use Infocyph\CacheLayer\Cluster\Exception\ClusterCacheException;
use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportInterface;
use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationTransport;
use Infocyph\CacheLayer\Cluster\Transport\RedisStreamInvalidationTransport;
use Infocyph\CacheLayer\Node\NodeCacheConfig;

function invalidationNode(InvalidationTransportInterface $transport, string $id, string $namespace = 'orders', string $history = 'v1'): ClusterRuntime
{
    $root = evidenceDirectory() . '/nodes/' . $id;
    if (!is_dir($root)) {
        verify(mkdir($root, 0700, true), 'Node directory unavailable.');
    }

    return ClusterCache::create(
        new NodeCacheConfig($root . '/local.sqlite', namespace: $namespace, apcuEnabled: false),
        new ClusterCacheConfig('laboratory', $id, $history, consumerBatchSize: 16),
        $transport,
    );
}

function invalidateCommon(InvalidationTransportInterface $transport): void
{
    $a = invalidationNode($transport, 'node-a');
    $b = invalidationNode($transport, 'node-b');
    $foreign = invalidationNode($transport, 'node-c', 'foreign');
    foreach ([$a, $b, $foreign] as $node) {
        verify($node->cache()->set('shared', 'stale', 600), 'Node seed failed.');
        verify($node->cache()->set('untouched', 'keep', 600), 'Node seed failed.');
    }
    $a->invalidateKey('shared');
    verify(!$a->cache()->has('shared') && $b->cache()->has('shared'), 'Local/eventual boundary failed.');
    $b->drain();
    $foreign->drain();
    verify(!$b->cache()->has('shared') && $foreign->cache()->get('shared') === 'stale', 'Namespace isolation failed.');
    verify($b->cache()->get('untouched') === 'keep', 'Key invalidation cleared unrelated key.');
    $a->drain();
    $cursor = $b->status()->cursor;
    unset($b);
    for ($i = 0; $i < 64; ++$i) {
        $a->invalidateKey('offline-' . $i);
    }
    $a->invalidateKey('untouched');
    $b = invalidationNode($transport, 'node-b');
    verify($b->status()->cursor === $cursor && $b->cache()->get('untouched') === 'keep', 'Replacement lost durable cursor/local cache.');
    $b->drain(16, 10);
    verify(!$b->cache()->has('untouched') && $b->status()->cursor !== $cursor, 'Offline catchup failed.');
    $foreign->drain();
    verify($foreign->cache()->get('untouched') === 'keep', 'Offline replay crossed namespace.');
    $b->cache()->set('generation', 'old', 600);
    unset($b);
    $b = invalidationNode($transport, 'node-b', history: 'v2');
    verify(!$b->cache()->has('generation'), 'New history identity retained stale cache.');
    $a->cache()->set('reset', 1, 600);
    $b->cache()->set('reset', 1, 600);
    $a->clearNamespace();
    $b->drain();
    verify(!$a->cache()->has('reset') && !$b->cache()->has('reset') && $foreign->cache()->has('shared'), 'Namespace reset crossed boundary.');
}

function invalidationSqlWorkload(): void
{
    $driver = (string) getenv('DB_BACKEND');
    $dsn = $driver === 'pgsql' ? 'pgsql:host=127.0.0.1;port=5432;dbname=experiment' : 'mysql:host=127.0.0.1;port=3306;dbname=experiment';
    $pdo = new PDO($dsn, 'experiment', 'experiment', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $transport = new PdoInvalidationTransport($pdo);
    invalidateCommon($transport);
    $peer = new PDO($dsn, 'experiment', 'experiment', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $remoteTransport = new PdoInvalidationTransport($peer, initializeSchema: false);
    $local = invalidationNode($transport, 'transaction-local');
    $remote = invalidationNode($remoteTransport, 'transaction-remote');
    $local->drain();
    $remote->drain();
    $pdo->exec('CREATE TABLE lab_cache_source (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');
    $pdo->exec('INSERT INTO lab_cache_source VALUES (1, 100)');
    $local->cache()->set('source', 100, 600);
    $remote->cache()->set('source', 100, 600);
    expectFailure(static fn() => $local->outbox($pdo), ClusterCacheException::class, 'Outbox accepted no transaction.');
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE lab_cache_source SET value=101 WHERE id=1');
        $rollback = $local->outbox($pdo);
        $rollback->invalidateKey('source');
        expectFailure($rollback->applyLocally(...), ClusterCacheException::class, 'Uncommitted outbox applied locally.');
        verify($remote->drain() === 0 && $remote->cache()->get('source') === 100, 'Uncommitted invalidation leaked.');
    } finally {
        $pdo->rollBack();
    }
    verify($remote->drain() === 0 && (int) $peer->query('SELECT value FROM lab_cache_source')->fetchColumn() === 100 && $local->cache()->get('source') === 100, 'Rollback invalidation leaked.');
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE lab_cache_source SET value=102 WHERE id=1');
        $outbox = $local->outbox($pdo);
        $outbox->invalidateKey('source');
        $pdo->commit();
    } catch (Throwable $failure) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $failure;
    }
    $outbox->applyLocally();
    $outbox->applyLocally();
    $remote->drain();
    verify(!$local->cache()->has('source') && !$remote->cache()->has('source') && (int) $peer->query('SELECT value FROM lab_cache_source')->fetchColumn() === 102, 'Commit/outbox coherence failed.');
    echo "Real SQL invalidation, durable cursors, history change and transactional outbox passed\n";
}

function invalidationStreamWorkload(): void
{
    $redis = new Redis();
    verify($redis->connect('127.0.0.1', 6379, 2.0), 'Redis unavailable.');
    $transport = new RedisStreamInvalidationTransport($redis, prefix: 'lab:events:', maxLength: 10000);
    invalidateCommon($transport);
    $a = invalidationNode($transport, 'retention-a');
    $b = invalidationNode($transport, 'retention-b');
    $a->invalidateKey('anchor');
    $b->drain();
    $b->cache()->set('missed', 'stale', 600);
    $a->invalidateKey('missed');
    for ($i = 0; $i < 32; ++$i) {
        $a->invalidateKey('trim-' . $i);
    }
    $redis->xTrim('lab:events:laboratory', 8, false);
    verify($b->recoverIfRequired() && !$b->cache()->has('missed'), 'Retention gap did not force safe namespace reset.');
    echo "Real Redis Streams replay, offline replacement, history rotation and retention recovery passed\n";
}
