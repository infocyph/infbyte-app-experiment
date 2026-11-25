<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Integration\AMQP\AmqpTransport;
use League\Flysystem\FilesystemException;
use PhpAmqpLib\Connection\AMQPStreamConnection;

function remoteOrderBroker(): AMQPStreamConnection
{
    return new AMQPStreamConnection('127.0.0.1', 15672, 'experiment', 'fixture-only-password', connection_timeout: 2.0, read_write_timeout: 4.0);
}

function remoteOrderInsert(Connection $db, int $first, int $last): void
{
    for ($i = $first; $i <= $last; ++$i) {
        $id = 'order-' . $i;
        $db->transaction(static function (Connection $active) use ($id, $i): void {
            $active->table('lab_remote_orders')->insert(['id' => $id, 'amount' => $i * 10]);
            $active->table('lab_remote_outbox')->insert(['id' => $id, 'sequence_no' => $i, 'relayed' => 0]);
        });
    }
}

function remoteOrderRecovery(string $phase, int $worker = -1): void
{
    [$db, $serializer] = messagingSetup($phase === 'seed');
    $storage = storageOutageContext($phase === 'seed');
    $disk = $storage->filesystem();
    $foreign = $storage->filesystem('foreign');
    if ($phase === 'offline') {
        verify($worker >= 0 && $worker < 8, 'Unexpected offline worker.');
        expectFailure(static fn() => $disk->read('receipts/stable.txt'), FilesystemException::class, 'Stopped storage returned a value.');
        $unavailable = false;
        try {
            $unexpected = remoteOrderBroker();
            $unexpected->close();
        } catch (Exception) {
            $unavailable = true;
        }
        verify($unavailable, 'Stopped broker accepted connection.');
        remoteOrderInsert($db, 9 + $worker * 4, 12 + $worker * 4);
        echo "Offline worker $worker committed four orders and outbox entries\n";
        return;
    }
    if ($phase === 'seed') {
        $db->statement('CREATE TABLE lab_remote_orders (id VARCHAR(100) PRIMARY KEY, amount INTEGER NOT NULL)');
        $db->statement('CREATE TABLE lab_remote_outbox (id VARCHAR(100) PRIMARY KEY, sequence_no INTEGER NOT NULL, relayed INTEGER NOT NULL)');
        remoteOrderInsert($db, 1, 8);
        $disk->write('receipts/stable.txt', 'original');
        $foreign->write('receipts/stable.txt', 'foreign');
    } else {
        verify($phase === 'recover' && $db->table('lab_remote_orders')->count() === 40
            && $db->table('lab_remote_outbox')->where('relayed', '=', 0)->count() === 36
            && $db->table('lab_job_effects')->count() === 0, 'Offline SQL/outbox reconciliation failed.');
        verify($disk->read('receipts/stable.txt') === 'original' && $foreign->read('receipts/stable.txt') === 'foreign', 'Storage restart lost stable tenant data.');
    }
    $connection = remoteOrderBroker();
    $channel = $connection->channel();
    try {
        $channel->queue_declare('remote-orders', false, true, false, false);
        $channel->confirm_select();
        $confirmed = 0;
        $channel->set_ack_handler(static function () use (&$confirmed): void { ++$confirmed; });
        $channel->set_nack_handler(static function (): never { throw new RuntimeException('Broker rejected publication.'); });
        $channel->set_return_listener(static function (): never { throw new RuntimeException('Broker returned unrouted publication.'); });
        $transport = new AmqpTransport(new LabAmqpBackend($channel), $serializer);
        if ($phase === 'recover') {
            verify($transport->size('remote-orders') === 4, 'Durable broker restart lost seeded messages.');
        }
        $pending = $db->table('lab_remote_outbox')->where('relayed', '=', 0)->orderBy('sequence_no')->get();
        if ($phase === 'seed') {
            $pending = array_slice($pending, 0, 4);
        }
        foreach ($pending as $row) {
            $id = $row['id'];
            $sequence = (int) $row['sequence_no'];
            $key = 'receipts/' . $id . '.txt';
            $payload = "$id:" . ($sequence * 10);
            $disk->write($key, $payload);
            verify($disk->read($key) === $payload, 'Published receipt integrity failed.');
            $before = $confirmed;
            $transport->send(new Envelope(new LabJob($id, $sequence), [new MessageIdStamp($id)]), 'remote-orders');
            $channel->wait_for_pending_acks_returns(5.0);
            verify($confirmed === $before + 1, 'Broker confirmation missing.');
            verify($db->table('lab_remote_outbox')->where('id', '=', $id)->where('relayed', '=', 0)->update(['relayed' => 1]) === 1, 'Outbox settlement failed.');
        }
        if ($phase === 'seed') {
            verify($transport->size('remote-orders') === 4, 'Seeded broker publication count differs.');
            return;
        }
        $transport->send(new Envelope(new LabJob('order-1', 1), [new MessageIdStamp('order-1')]), 'remote-orders');
        $channel->wait_for_pending_acks_returns(5.0);
        verify($confirmed === 37, 'Duplicate publication was not confirmed.');
        $deliveries = 0;
        while ($transport->size('remote-orders') > 0 && $deliveries < 50) {
            foreach ($transport->receive('remote-orders', 8) as $delivery) {
                $job = $delivery->envelope()->message;
                verify($job instanceof LabJob && $delivery->messageId === $job->id, 'Broker identity differs.');
                verify($disk->read('receipts/' . $job->id . '.txt') === $job->id . ':' . ($job->step * 10), 'Consumer observed missing or incorrect storage effect.');
                $db->transaction(static function (Connection $active) use ($job): void {
                    $query = $active->table('lab_remote_orders')->where('id', '=', $job->id);
                    // SQLite serializes writes at the database boundary; it has no row-level FOR UPDATE.
                    // This recovery phase has one consumer and all offline writers have already exited.
                    $order = ($active->getDriverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
                    verify($order !== null && (int) $order['amount'] === $job->step * 10, 'Unknown business operation.');
                    $effect = $active->table('lab_job_effects')->find($job->id);
                    if ($effect === null) {
                        verify($active->table('lab_job_effects')->insert(['id' => $job->id, 'step' => $job->step]), 'Durable effect insert failed.');
                    } else {
                        verify((int) $effect['step'] === $job->step, 'Duplicate receipt changed durable effect.');
                    }
                });
                $transport->acknowledge($delivery);
                ++$deliveries;
            }
        }
        verify($deliveries === 41 && $transport->size('remote-orders') === 0
            && $db->table('lab_remote_outbox')->where('relayed', '=', 1)->count() === 40
            && $db->table('lab_job_effects')->count() === 40, 'Recovery did not reconcile exact durable effects.');
        for ($i = 1; $i <= 40; ++$i) {
            verify($disk->read('receipts/order-' . $i . '.txt') === "order-$i:" . ($i * 10), 'Recovered receipt mismatch.');
        }
        verify($foreign->read('receipts/stable.txt') === 'foreign' && $disk->read('receipts/stable.txt') === 'original', 'Tenant or stable data changed.');
        echo "Eight offline SQL writers, real durable broker/storage restart, 40 receipts and 41 deliveries with 40 effects reconciled\n";
    } finally {
        $connection->close();
    }
}

function remotePublicationOrder(string $backend, string $mode, string $phase): void
{
    verify(in_array($phase, ['seed', 'fault', 'recover', 'consume'], true), 'Unknown order publication phase.');
    [$db, $serializer] = messagingSetup($phase === 'seed');
    $id = 'receipt-publication';
    $digest = hash('sha256', str_repeat("receipt\0\xff\n", 16384));
    $queue = 'publication-orders';
    if ($phase === 'seed') {
        $db->statement('CREATE TABLE lab_publication_outbox (id VARCHAR(100) PRIMARY KEY, digest VARCHAR(64) NOT NULL, relayed INTEGER NOT NULL)');
        $db->transaction(static function (Connection $active) use ($id, $digest): void {
            verify($active->table('lab_publication_outbox')->insert(['id' => $id, 'digest' => $digest, 'relayed' => 0]), 'Durable intent insert failed.');
        });
        remoteHttpPublicationContracts($backend, $mode, 'seed');
    }
    $intent = $db->table('lab_publication_outbox')->find($id);
    verify($intent !== null && $intent['digest'] === $digest && $db->table('lab_publication_outbox')->count() === 1,
        'Durable publication intent lost or changed.');
    verify((int) $intent['relayed'] === ($phase === 'consume' ? 1 : 0)
        && $db->table('lab_job_effects')->count() === 0, 'Publication or business effect settled too early.');
    $connection = remoteOrderBroker();
    try {
        $channel = $connection->channel();
        $channel->queue_declare($queue, false, true, false, false);
        $transport = new AmqpTransport(new LabAmqpBackend($channel), $serializer);
        if ($phase === 'seed') {
            verify($transport->size($queue) === 0, 'Publication queue not empty at seed.');
            return;
        }
        if ($phase === 'fault') {
            remoteHttpPublicationContracts($backend, $mode, 'fault');
            verify($transport->size($queue) === 0 && (int) $db->table('lab_publication_outbox')->find($id)['relayed'] === 0
                && $db->table('lab_job_effects')->count() === 0, 'Uncertain storage write incorrectly settled durable intent.');
            echo "Unconfirmed write left outbox pending and broker empty\n";
            return;
        }
        if ($phase === 'recover') {
            verify($transport->size($queue) === 0, 'Unexpected publication before reconciliation.');
            remoteHttpPublicationContracts($backend, $mode, 'recover');
            $channel->confirm_select();
            $confirmed = 0;
            $channel->set_ack_handler(static function () use (&$confirmed): void { ++$confirmed; });
            $channel->set_nack_handler(static function (): never { throw new RuntimeException('Broker rejected receipt publication.'); });
            $channel->set_return_listener(static function (): never { throw new RuntimeException('Receipt publication was unrouted.'); });
            // Simulate relay retry before the durable outbox is marked settled.
            $envelope = new Envelope(new LabJob($id, 1), [new MessageIdStamp($id)]);
            foreach ([1, 2] as $attempt) {
                $transport->send($envelope, $queue);
                $channel->wait_for_pending_acks_returns(5.0);
                verify($confirmed === $attempt, 'Receipt publication confirmation missing.');
            }
            verify($db->table('lab_publication_outbox')->where('id', '=', $id)->where('relayed', '=', 0)->update(['relayed' => 1]) === 1,
                'Durable publication settlement failed.');
            verify($transport->size($queue) === 2, 'Confirmed duplicate publication missing.');
            return;
        }
        $peer = additionalStorageContext($backend);
        $disk = $peer->filesystem();
        $foreign = $peer->filesystem('foreign');
        verify($transport->size($queue) === 2, 'Replacement consumer did not observe both confirmed deliveries.');
        $deliveries = 0;
        foreach ($transport->receive($queue, 2) as $delivery) {
            $job = $delivery->envelope()->message;
            verify($job instanceof LabJob && $job->id === $id && $job->step === 1 && $delivery->messageId === $id,
                'Unexpected receipt publication identity.');
            verify(hash('sha256', $disk->read('receipts/final.bin')) === $digest && !$disk->fileExists('receipts/fault-stage.bin'),
                'Consumer observed partial or missing publication.');
            brokerRecordEffect($db, $job);
            $transport->acknowledge($delivery);
            ++$deliveries;
        }
        $channel->queue_declare($queue, true);
        verify($deliveries === 2 && $transport->size($queue) === 0 && $db->table('lab_job_effects')->count() === 1
            && (int) $db->table('lab_job_effects')->find($id)['step'] === 1
            && (int) $db->table('lab_publication_outbox')->find($id)['relayed'] === 1, 'Durable reconciliation mismatch.');
        verify($foreign->read('receipts/final.bin') === 'foreign-final' && $foreign->read('receipts/fault-stage.bin') === 'foreign-stage',
            'Publication crossed tenant boundary.');
        echo "$backend $mode: four fresh processes, confirmed retry and one durable effect reconciled\n";
    } finally {
        $connection->close();
    }
}
