<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Messaging\MessagingDatabaseSchema;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\MessageBus;
use Infocyph\ReqShield\Validator;
use Infocyph\UID\Id;
use Infocyph\Webrick\Response\Response;

final readonly class ConnectedOrders
{
    public function __construct(private Application $app) {}

    public function setup(): void
    {
        $this->app->make(MessagingDatabaseSchema::class)->install();
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $db->statement('CREATE TABLE lab_connected_stock (id INTEGER PRIMARY KEY, quantity INTEGER NOT NULL)');
        $db->statement('INSERT INTO lab_connected_stock (id, quantity) VALUES (1, 10000)');
        $db->statement('CREATE TABLE lab_connected_orders (id VARCHAR(80) PRIMARY KEY, request_id VARCHAR(80) NOT NULL UNIQUE, quantity INTEGER NOT NULL, notified INTEGER NOT NULL DEFAULT 0)');
        $db->statement('CREATE TABLE lab_connected_outbox (id VARCHAR(80) PRIMARY KEY, sent INTEGER NOT NULL DEFAULT 0, FOREIGN KEY (id) REFERENCES lab_connected_orders(id))');
    }

    public function create(string $id, string $quantity): Response
    {
        $result = Validator::make(['quantity' => 'required|integer|min:1|max:5'])->validate(['quantity' => $quantity]);
        if (!$result->passes() || preg_match('/^[a-z0-9-]{1,60}$/D', $id) !== 1) {
            return Response::json(['error' => 'invalid'], 422);
        }
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $created = $db->transaction(static function () use ($db, $id, $quantity): array {
            // Serialize competing checkout claims on the same inventory row.
            $stock = $db->select('SELECT quantity FROM lab_connected_stock WHERE id = 1 FOR UPDATE')[0];
            $existing = $db->select('SELECT id, quantity FROM lab_connected_orders WHERE request_id = ?', [$id]);
            if ($existing !== []) {
                return ['id' => $existing[0]['id'], 'quantity' => (int) $existing[0]['quantity'], 'created' => false];
            }
            ensure((int) $stock['quantity'] >= (int) $quantity, 'Insufficient fixture stock.');
            $order = Id::uuid7();
            $db->statement('UPDATE lab_connected_stock SET quantity = quantity - ? WHERE id = 1', [(int) $quantity]);
            $db->statement('INSERT INTO lab_connected_orders (id, request_id, quantity) VALUES (?, ?, ?)', [$order, $id, (int) $quantity]);
            $db->statement('INSERT INTO lab_connected_outbox (id) VALUES (?)', [$order]);

            return ['id' => $order, 'quantity' => (int) $quantity, 'created' => true];
        });
        if ($created['quantity'] !== (int) $quantity) {
            return Response::json(['error' => 'idempotency conflict'], 409);
        }

        return Response::json($created, $created['created'] ? 201 : 200);
    }

    public function relay(bool $abort = false): int
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $bus = $this->app->make(MessageBus::class);
        return $db->transaction(static function () use ($db, $bus, $abort): int {
            $rows = $db->select('SELECT id FROM lab_connected_outbox WHERE sent = 0 ORDER BY id LIMIT 16 FOR UPDATE');
            foreach ($rows as $row) {
                $bus->dispatch(new Receipt($row['id'], 'normal'));
                $db->statement('UPDATE lab_connected_outbox SET sent = 1 WHERE id = ?', [$row['id']]);
            }
            if ($abort) {
                ensure($rows !== [], 'Rollback fixture has no outbox records.');
                throw new \DomainException('intentional relay rollback');
            }

            return count($rows);
        });
    }

    public function verifyRelayRollback(): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM lab_connected_outbox WHERE sent = 1')) === 0, 'Rollback marked outbox sent.');
        ensure($this->app->make(DBLayerTransport::class)->size('receipts') === 0, 'Rolled-back relay leaked queue records.');
    }

    public function verify(int $expected): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM lab_connected_orders')) === $expected, 'Order count mismatch.');
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM lab_connected_orders WHERE notified = 1')) === $expected, 'Notification state mismatch.');
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM lab_connected_outbox WHERE sent = 1')) === $expected, 'Outbox reconciliation failed.');
        ensure(countValue($db->scalar('SELECT quantity FROM lab_connected_stock WHERE id = 1')) === 10000 - 2 * $expected, 'Inventory conservation failed.');
        ensure($this->app->make(DBLayerTransport::class)->size('receipts') === 0, 'Queue not drained.');
        $handler = new ConnectedReceipt($this->app);
        foreach ($db->select('SELECT id, quantity FROM lab_connected_orders') as $row) {
            $handler->verifyDocument($row['id'], (int) $row['quantity']);
            if (getenv('SCENARIO') === 'ecosystem-active-recovery') {
                $plain = json_encode(['order' => $row['id'], 'quantity' => (int) $row['quantity']], JSON_THROW_ON_ERROR);
                ensure($this->app->make(CacheManager::class)->store('shared')->get('connected.' . $row['id']) === hash('sha256', $plain), 'Recovered receipt cache diverged.');
            }
        }
        new Mail($this->app)->verify($expected);
    }
}
