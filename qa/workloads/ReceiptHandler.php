<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class ReceiptHandler
{
    public function __construct(private Application $app) {}

    /** @throws \Throwable */
    public function __invoke(Receipt $message): void
    {
        if ($message->kind === 'fail' || $message->kind === 'rollback') {
            throw new \RuntimeException('Intentional permanent receipt failure.');
        }

        $db = $this->app->make(DBLayerFactory::class)->connection();
        $mysql = getenv('DB_BACKEND') === 'mysql';
        $upsert = $mysql ? ' ON DUPLICATE KEY UPDATE attempts = attempts + 1' : ' ON CONFLICT (id) DO UPDATE SET attempts = experiment_attempts.attempts + 1';
        $db->statement('INSERT INTO experiment_attempts (id, attempts) VALUES (?, 1)' . $upsert, [$message->id]);
        if ($message->kind === 'retry' && countValue($db->scalar('SELECT attempts FROM experiment_attempts WHERE id = ?', [$message->id])) === 1) {
            throw new \RuntimeException('Intentional transient receipt failure.');
        }

        $dedup = $mysql ? ' ON DUPLICATE KEY UPDATE id = id' : ' ON CONFLICT (id) DO NOTHING';
        $db->statement('INSERT INTO experiment_receipts (id) VALUES (?)' . $dedup, [$message->id]);
    }
}
