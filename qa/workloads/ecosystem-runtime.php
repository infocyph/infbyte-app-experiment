<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Pathwise\Exceptions\PathwiseException;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadSource;
use Infocyph\Pathwise\StreamHandler\MalwareScanMode;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Exception\ChannelClosedException;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\ReleaseCompiler;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Psr\Log\NullLogger;

final class LabRouteHandlers
{
    public static function status(): Response
    {
        return Response::plaintext('ready');
    }

    public static function customer(string $id): Response
    {
        return Response::json(['id' => (int) $id]);
    }
}

function routingWorkload(): void
{
    $dir = evidenceDirectory() . '/routing-release';
    mkdir($dir, 0700);
    $builder = ContainerBuilder::create('lab-routing');
    $release = new ReleaseCompiler()->compile(
        builder: $builder,
        register: static function (Registrar $routes): void {
            $routes->group(prefix: '/api', namePrefix: 'api.', callback: static function (Registrar $api): void {
                $api->group(prefix: '/v1', namePrefix: 'v1.', callback: static function (Registrar $v1): void {
                    $v1->get('/customers/new', LabRouteHandlers::status(...), 'customers.new');
                    $v1->get('/customers/{id:int}', LabRouteHandlers::customer(...), 'customers.show');
                });
            });
            $routes->group(prefix: '/admin', domain: 'admin.example.test', namePrefix: 'admin.', callback: static function (Registrar $admin): void {
                $admin->get('/status', LabRouteHandlers::status(...), 'status');
            });
            for ($i = 0; $i < 1500; ++$i) {
                $routes->get('/catalog/section-' . $i, LabRouteHandlers::status(...), 'catalog.' . $i);
            }
        },
        environment: 'production',
        configFingerprint: hash('xxh128', 'routing-lab'),
        intermixPath: $dir . '/intermix.php',
        routerPath: $dir . '/webrick.php',
        releaseManifestPath: $dir . '/release.json',
    );
    $matcher = match (getenv('LAB_MATCHER')) {
        'fused' => FusedMatcher::make(),
        'sharded' => ShardedMatcher::make(),
        default => throw new RuntimeException('Unexpected routing matcher.'),
    };
    $kernel = CompiledRouterKernel::fromPrevalidatedArtifact(
        log: new NullLogger(),
        matcher: $matcher,
        container: $builder->productionPrevalidated($release['intermix']['path'], $release['intermix']['digest']),
        artifactPath: $release['webrick']['path'],
        trustedArtifactFingerprint: $release['webrick']['fingerprint'],
        environment: 'production',
        configFingerprint: $release['config_fingerprint'],
    );
    $request = static fn(string $method, string $uri): Response => $kernel->handle(Request::fake(method: $method, uri: $uri));
    verify($request('GET', 'http://example.test/api/v1/customers/new')->getStringBody() === 'ready', 'Literal precedence failed.');
    $typed = $request('GET', 'http://example.test/api/v1/customers/42');
    verify($typed->getStatusCode() === 200 && json_decode((string) $typed->getStringBody(), true, flags: JSON_THROW_ON_ERROR)['id'] === 42, 'Compiled typed parameter dispatch failed.');
    verify($request('GET', 'http://example.test/api/v1/customers/abc')->getStatusCode() === 404, 'Integer route constraint bypassed.');
    verify($request('POST', 'http://example.test/api/v1/customers/42')->getStatusCode() === 405, 'Method mismatch not classified.');
    verify($request('GET', 'http://admin.example.test/admin/status')->getStatusCode() === 200, 'Domain-scoped route missing.');
    verify($request('GET', 'http://example.test/admin/status')->getStatusCode() === 404, 'Cross-domain route leaked.');
    for ($i = 0; $i < 1500; ++$i) {
        verify($request('GET', 'http://example.test/catalog/section-' . $i)->getStringBody() === 'ready', 'Compiled large-route corpus mismatch.');
    }
    echo "Compiled route groups, domains, method/constraint decisions and 1500 static routes passed\n";
}

function coroutinesWorkload(): void
{
    $runtime = new CoroutineRuntime();
    $count = $runtime->run(static function (CoroutineScope $scope): int {
        $channel = $scope->channel(8);
        $producer = $scope->spawn(static function () use ($channel): void {
            for ($i = 1; $i <= 5000; ++$i) {
                $channel->send($i);
            }
            $channel->close();
        });
        $seen = 0;
        for ($i = 1; $i <= 5000; ++$i) {
            verify($channel->receive() === $i, 'Bounded channel lost FIFO ordering.');
            ++$seen;
        }
        $producer->await();
        expectFailure(static fn() => $channel->receive(), ChannelClosedException::class, 'Closed channel did not terminate.');
        $semaphore = $scope->semaphore(4);
        $active = 0;
        $peak = 0;
        $tasks = [];
        for ($i = 0; $i < 24; ++$i) {
            $tasks[] = $scope->spawn(static function () use ($scope, $semaphore, &$active, &$peak): void {
                $semaphore->acquire();
                try {
                    ++$active;
                    $peak = max($active, $peak);
                    $scope->sleep(0.001);
                } finally {
                    --$active;
                    $semaphore->release();
                }
            });
        }
        foreach ($tasks as $task) {
            $task->await();
        }
        verify($active === 0 && $peak === 4 && $semaphore->availablePermits() === 4, 'Semaphore capacity or ownership failed.');

        return $seen;
    });
    verify($count === 5000 && $runtime->activeTaskCount() === 0, 'Task leak after structured scope.');
    $cleanup = false;
    $failure = new DomainException('Intentional child failure');
    try {
        $runtime->run(static function (CoroutineScope $scope) use (&$cleanup, $failure): void {
            $scope->spawn(static function () use ($scope, &$cleanup): void {
                try {
                    $scope->sleep(30.0);
                } finally {
                    $cleanup = true;
                }
            });
            $scope->spawn(static function () use ($failure): never {
                throw $failure;
            });
        });
        throw new RuntimeException('Child failure was swallowed.');
    } catch (DomainException $caught) {
        verify($caught === $failure, 'Child failure identity lost.');
    }
    verify($cleanup && $runtime->activeTaskCount() === 0, 'Failed scope did not retire sibling.');
    echo "5000 bounded channel messages, semaphore concurrency and child failure cleanup passed\n";
}

function asyncFilesWorkload(): void
{
    $root = evidenceDirectory() . '/async-files';
    mkdir($root, 0700);
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 4, 64);
    $files = new AsyncFileSystem($executor);
    $results = [];
    try {
        for ($i = 0; $i < 32; ++$i) {
            $path = $root . '/' . $i;
            $data = random_bytes(32768);
            file_put_contents($path, '');
            $files->write($path, $data, static function (int $written, ?Throwable $error) use ($files, $path, $data, $i, &$results): void {
                verify($error === null && $written === strlen($data), 'Off-loop write failed.');
                $files->read($path, strlen($data), static function (string $bytes, ?Throwable $error) use ($data, $i, &$results): void {
                    verify($error === null && hash_equals(hash('sha256', $data), hash('sha256', $bytes)), 'Off-loop read integrity failed.');
                    $results[$i] = true;
                });
            });
        }
        $executor->onDrained(static function () use ($executor): void {
            $executor->close();
        });
        $loop->run();
        verify(count($results) === 32 && $executor->diagnostics()['workers'] === 0, 'Async completion or worker drain failed.');
        verify($loop->diagnostics()->readWatchers === 0 && $loop->diagnostics()->writeWatchers === 0, 'Async pipe watchers leaked.');
    } finally {
        $executor->close();
        foreach (glob($root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($root);
    }
    echo "32 real process-executor file roundtrips and pipe/worker drain passed\n";
}

function pathwiseUploadWorkload(): void
{
    $dir = evidenceDirectory() . '/uploads';
    mkdir($dir . '/published', 0700, true);
    mkdir($dir . '/staging', 0700);
    $processor = new UploadProcessor();
    $processor->setDirectorySettings($dir . '/published', tempDir: $dir . '/staging');
    $processor->setValidationSettings(['text/plain'], 1048576);
    $processor->setExtensionPolicy(['txt']);
    $processor->setNamingStrategy('hash');
    $processor->setChunkLimits(8, 131072);
    $data = str_repeat("invoice line 0123456789\n", 1024);
    $stream = fopen('php://temp', 'w+b');
    verify(is_resource($stream), 'Upload source unavailable.');
    fwrite($stream, $data);
    rewind($stream);
    $file = $processor->ingestSource(UploadSource::fromStream($stream, 'invoice.txt', strlen($data), 'text/plain'));
    verify(is_resource($stream) && hash_file('sha256', $file) === hash('sha256', $data), 'Borrowed stream or publication failed.');
    fclose($stream);
    $parts = str_split($data, 10000);
    $source = static function (string $part): UploadSource {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $part);
        rewind($stream);

        return UploadSource::fromStream($stream, 'chunk.txt', strlen($part), 'text/plain');
    };
    $id = 'lab-resumable-upload';
    $processor->processChunkUploadSource($source($parts[1]), $id, 1, count($parts), 'invoice-chunks.txt');
    expectFailure(static fn() => $processor->finalizeChunkUpload($id), PathwiseException::class, 'Incomplete upload published.');
    $processor->processChunkUploadSource($source($parts[0]), $id, 0, count($parts), 'invoice-chunks.txt');
    for ($i = 2; $i < count($parts); ++$i) {
        $processor->processChunkUploadSource($source($parts[$i]), $id, $i, count($parts), 'invoice-chunks.txt');
    }
    $merged = $processor->finalizeChunkUpload($id);
    verify(hash_file('sha256', $merged) === hash('sha256', $data), 'Out-of-order chunk reconstruction failed.');
    $processor->setMalwareScanMode(MalwareScanMode::REQUIRED);
    expectFailure(static fn() => $processor->ingestSource($source('scanner required')), PathwiseException::class, 'Required missing scanner accepted upload.');
    echo "Borrowed stream ingestion, resumable out-of-order chunk assembly and mandatory scanner failure passed\n";
}

function pathwiseDownloadWorkload(): void
{
    $root = evidenceDirectory() . '/download';
    mkdir($root . '/public', 0700, true);
    $data = str_repeat('0123456789abcdef', 8192);
    $file = $root . '/public/document.txt';
    file_put_contents($file, $data);
    file_put_contents($root . '/private.txt', 'private');
    $processor = new DownloadProcessor();
    $processor->setAllowedRoots([$root . '/public']);
    $processor->setExtensionPolicy(['txt']);
    $prepared = $processor->prepareDownload($file, 'document.txt', 'bytes=1024-4095');
    verify($prepared->status === 206, 'Range status mismatch.');
    $body = '';
    foreach ($processor->streamChunks($prepared) as $chunk) {
        $body .= $chunk;
    }
    verify($body === substr($data, 1024, 3072), 'Exact range body mismatch.');
    expectFailure(static fn() => $processor->prepareDownload($file, 'document.txt', 'bytes=999999-'), PathwiseException::class, 'Unsatisfiable range accepted.');
    expectFailure(static fn() => $processor->prepareDownload($root . '/private.txt'), PathwiseException::class, 'Outside-root file accepted.');
    symlink($root . '/private.txt', $root . '/public/escape.txt');
    expectFailure(static fn() => $processor->prepareDownload($root . '/public/escape.txt'), PathwiseException::class, 'Symlink escaped authorized root.');
    file_put_contents($file, 'replacement');
    clearstatcache(true, $file);
    expectFailure(static function () use ($processor, $prepared): void {
        foreach ($processor->streamChunks($prepared) as $chunk) {
            unset($chunk);
        }
    }, PathwiseException::class, 'Stale preparation authorized changed file.');
    echo "Range download, root and symlink containment and stale preparation rejection passed\n";
}
