<?php

declare(strict_types=1);

use Experiment\Receipt;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Messaging\MessagingDatabaseSchema;
use Infocyph\Foundation\Operations\RuntimeControl;
use Infocyph\Foundation\Operations\RuntimeProcessRegistry;
use Infocyph\Foundation\Worker\WorkerManager;
use Infocyph\Omnibus\Failure\FailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\MessageBus;

require __DIR__ . '/bootstrap.php';

function poolVerify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final readonly class LabPooledReceiptHandler
{
    public function __construct(private DBLayerFactory $database) {}

    public function __invoke(Receipt $message): void
    {
        $root = (string) getenv('LAB_POOL_ROOT');
        if ($message->kind === 'crash') {
            $claim = @fopen($root . '/crash-owner', 'x');
            if (is_resource($claim)) {
                poolVerify(fwrite($claim, (string) getmypid()) > 0, 'Crash evidence unavailable.');
                fclose($claim);
                posix_kill(getmypid(), SIGKILL);
                throw new RuntimeException('Crash signal did not terminate process.');
            }
        }
        // A committed effect includes the real child PID. No upsert masks duplicate handling.
        $db = $this->database->connection();
        poolVerify($db->table('lab_pool_effects')->insert(['id' => $message->id, 'worker_pid' => getmypid()]), 'Pool effect insertion failed.');
    }
}

$root = (string) getenv('LAB_POOL_ROOT');
poolVerify(is_dir($root), 'Pool evidence directory unavailable.');
$config = \Experiment\configuration();
$config['app']['capabilities'] = ['database', 'messaging'];
$config['providers'] = ['common' => [], 'web' => [], 'cli' => [], 'worker' => [], 'scheduler' => []];
$config['operations']['runtime_control'] = ['driver' => 'file', 'path' => $root . '/control.json'];
$config['operations']['runtime_registry'] = ['path' => $root . '/registry', 'visibility' => 'host', 'stale_seconds' => 15];
$config['messaging']['handlers'] = [Receipt::class => LabPooledReceiptHandler::class];
$config['messaging']['default_route']['queue'] = 'receipts';
$config['messaging']['routes'][Receipt::class]['queue'] = 'receipts';
$config['messaging']['workers'] = [
    'lab-pool' => [
        'transport' => 'database', 'queue' => 'receipts', 'prefetch' => 1, 'visibility_seconds' => 3.0,
        'idle_sleep_seconds' => 0.02, 'max_idle_sleep_seconds' => 0.1, 'idle_jitter_ratio' => 0.0,
        'max_messages' => 2, 'max_runtime_seconds' => 30, 'handle_signals' => true,
        'pool' => ['enabled' => true, 'concurrency' => 2, 'maximum_restarts' => 3,
            'restart_backoff_seconds' => 0.1, 'shutdown_grace_seconds' => 5.0],
    ],
];
$mode = $argv[1] ?? '';
$contract = $argv[2] ?? '';
poolVerify(in_array($contract, ['recycle', 'crash', 'parent-guard'], true), 'Unknown pool contract.');
$app = Foundation::worker($config);
$manager = new WorkerManager($app);
if ($mode === 'run') {
    poolVerify(!$app->booted(), 'Pool parent already booted.');
    poolVerify($manager->run('lab-pool', $root . '/no-provider-routes.php') === 0, 'Pool supervisor returned failure.');
    exit;
}
if ($mode === 'guard') {
    $app->boot();
    try {
        $manager->run('lab-pool', $root . '/no-provider-routes.php');
    } catch (LogicException $exception) {
        poolVerify(str_contains($exception->getMessage(), 'before booting'), 'Unexpected pool parent rejection.');
        poolVerify(new RuntimeProcessRegistry($app)->all('worker', 'lab-pool') === [], 'Rejected parent leaked registry record.');
        echo "Prebooted parent correctly rejected\n";
        exit;
    }
    throw new RuntimeException('Prebooted pool parent was accepted.');
}
$app->boot();
$registry = new RuntimeProcessRegistry($app);
$transport = $app->make(DBLayerTransport::class);
if ($mode === 'seed') {
    $app->make(MessagingDatabaseSchema::class)->install();
    $app->execution()->run(static function () use ($app, $contract): void {
        $db = $app->make(DBLayerFactory::class)->connection();
        $db->statement('CREATE TABLE lab_pool_effects (id VARCHAR(80) PRIMARY KEY, worker_pid INTEGER NOT NULL)');
        $bus = $app->make(MessageBus::class);
        for ($i = 1; $i <= 12; ++$i) {
            $bus->dispatch(new Receipt('receipt-' . sprintf('%02d', $i), $i === 1 && $contract === 'crash' ? 'crash' : 'normal'));
        }
    });
    poolVerify($transport->size('receipts') === 12, 'Pool seed queue differs.');
    exit;
}
$rows = static fn(): array => $app->execution()->run(static fn(): array => $app->make(DBLayerFactory::class)->connection()->select('SELECT id, worker_pid FROM lab_pool_effects ORDER BY id'));
if ($mode === 'observe') {
    $deadline = hrtime(true) + 80_000_000_000;
    do {
        $records = $registry->all('worker', 'lab-pool');
        $effects = $rows();
        if (count($effects) === 12 && $transport->size('receipts') === 0 && count($records) === 1 && $records[0]['running']) {
            break;
        }
        usleep(100000);
    } while (hrtime(true) < $deadline);
    poolVerify(count($effects) === 12 && $transport->size('receipts') === 0 && count($records) === 1 && $records[0]['running'], 'Pool did not drain with live supervisor.');
    $parent = (int) $records[0]['pid'];
    $pids = array_map('intval', array_column($effects, 'worker_pid'));
    poolVerify(count(array_unique($pids)) >= 6 && !in_array($parent, $pids, true), 'Pool did not recycle real child processes.');
    foreach (array_count_values($pids) as $count) {
        poolVerify($count <= 2, 'Child exceeded configured message bound.');
    }
    if ($contract === 'crash') {
        $crashed = (int) file_get_contents($root . '/crash-owner');
        poolVerify($crashed > 0 && !in_array($crashed, $pids, true) && !posix_kill($crashed, 0), 'Crashed reservation was not recovered by replacement process.');
    }
    $control = new RuntimeControl($app);
    $before = $control->token('worker', 'lab-pool');
    $signal = $control->signal('worker', 'lab-pool');
    poolVerify($signal !== $before && $control->changed('worker', 'lab-pool', $before), 'Named stop signal was not published.');
    file_put_contents($root . '/observed.json', json_encode(['parent' => $parent, 'children' => $pids, 'effects' => $effects], JSON_THROW_ON_ERROR), LOCK_EX);
    exit;
}
poolVerify($mode === 'verify', 'Unknown pool phase.');
poolVerify($registry->all('worker', 'lab-pool') === [], 'Pool exit leaked runtime registry record.');
poolVerify($transport->size('receipts') === 0 && $app->make(FailureStore::class)->all(100) === [], 'Pool exit left pending or failed receipts.');
$effects = $rows();
$expected = array_map(static fn(int $i): string => 'receipt-' . sprintf('%02d', $i), range(1, 12));
poolVerify(array_column($effects, 'id') === $expected, 'Durable receipt reconciliation differs.');
$evidence = json_decode((string) file_get_contents($root . '/observed.json'), true, flags: JSON_THROW_ON_ERROR);
poolVerify($evidence['effects'] === $effects, 'Durable ledger changed after supervisor exit.');
echo json_encode($evidence, JSON_THROW_ON_ERROR), PHP_EOL;
