<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\DBLayer\Exceptions\TransactionException;
use Infocyph\Foundation\Messaging\ConsumerFactory;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\MessageBus;

require __DIR__ . '/bootstrap.php';

$app = application();
$orders = new ConnectedOrders($app);
match ($argv[1] ?? '') {
    'pending' => $app->execution()->run(static function () use ($app, $argv): void {
        $db = $app->make(\Infocyph\Foundation\Database\DBLayerFactory::class)->connection();
        $expected = (int) $argv[2];
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM lab_connected_orders WHERE notified = 0')) === $expected, 'Pending business state changed.');
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM lab_connected_outbox WHERE sent = 1')) === $expected, 'Durable outbox state changed.');
        ensure($app->make(\Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport::class)->size('receipts') === $expected, 'Pending queue count changed.');
    }),
    'reserve-recovery' => (static function () use ($app): never {
        $transport = $app->make(\Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport::class);
        foreach ($transport->receive('receipts', 1, 3.0) as $reservation) {
            ensure($reservation->envelope()->message instanceof Receipt, 'Unexpected reserved message.');
            ensure(file_put_contents(getenv('RUNNER_TEMP') . '/connected-reserved', 'reserved', LOCK_EX) !== false, 'Reservation handshake failed.');
            sleep(60);
        }
        throw new \RuntimeException('Expected supervisor termination.');
    })(),
    'setup' => $app->execution()->run($orders->setup(...)),
    'rollback' => $app->execution()->run(static function () use ($orders): void {
        try {
            $orders->relay(true);
            throw new \LogicException('Expected relay rollback.');
        } catch (TransactionException $failure) {
            ensure($failure->getPrevious() instanceof \DomainException, 'Unexpected rollback reason.');
        }
        $orders->verifyRelayRollback();
    }),
    'relay' => (static function () use ($app, $orders): void {
        $deadline = hrtime(true) + 60_000_000_000;
        while (hrtime(true) < $deadline) {
            if ($app->execution()->run($orders->relay(...)) === 0) {
                return;
            }
        }
        throw new \RuntimeException('Relay did not drain.');
    })(),
    'drain' => (static function () use ($app): void {
        $consumer = $app->make(ConsumerFactory::class)->make('database');
        $deadline = hrtime(true) + 120_000_000_000;
        $idle = 0;
        while (hrtime(true) < $deadline) {
            $result = $consumer->run('receipts', 8, 20.0);
            if ($result->received > 0) {
                $idle = 0;
                continue;
            }
            if (++$idle >= 30) {
                return;
            }
            usleep(100000);
        }
        throw new \RuntimeException('Consumer did not drain.');
    })(),
    'duplicate' => $app->execution()->run(static function () use ($app, $argv): void {
        $app->make(MessageBus::class)->dispatch(new Receipt($argv[2], 'normal'));
    }),
    'verify' => $app->execution()->run(static function () use ($app, $orders, $argv): void {
        $orders->verify((int) $argv[2]);
        ensure(count($app->make(DBLayerFailureStore::class)->all(1000)) === 0, 'Unexpected dead letters.');
    }),
    default => throw new \InvalidArgumentException('Unknown connected worker mode.'),
};
