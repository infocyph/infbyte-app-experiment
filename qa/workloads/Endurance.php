<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\ReqShield\Validator;
use Infocyph\Webrick\Response\Response;

final readonly class Endurance
{
    public function __construct(private Application $app) {}

    public function setup(): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $db->statement('CREATE TABLE lab_endurance_tenant (id INTEGER PRIMARY KEY, total INTEGER NOT NULL)');
        $db->statement('CREATE TABLE lab_endurance_ledger (id VARCHAR(64) PRIMARY KEY, tenant INTEGER NOT NULL, FOREIGN KEY (tenant) REFERENCES lab_endurance_tenant(id))');
        for ($i = 1; $i <= 8; ++$i) {
            $db->statement('INSERT INTO lab_endurance_tenant (id, total) VALUES (?, 0)', [$i]);
        }
    }

    public function create(string $tenant, string $id): Response
    {
        if (!Validator::make(['tenant' => 'required|integer|min:1|max:8'])->validate(['tenant' => $tenant])->passes()
            || preg_match('/^[a-z0-9-]{1,48}$/D', $id) !== 1) {
            return Response::json(['error' => 'invalid'], 422);
        }
        $owner = (int) $tenant;
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $state = $db->transaction(static function () use ($db, $id, $owner): array {
            $db->select('SELECT id FROM lab_endurance_tenant WHERE id = ? FOR UPDATE', [$owner]);
            $previous = $db->select('SELECT tenant FROM lab_endurance_ledger WHERE id = ?', [$id]);
            if ($previous !== []) {
                return ['tenant' => (int) $previous[0]['tenant'], 'created' => false];
            }
            $db->statement('INSERT INTO lab_endurance_ledger (id, tenant) VALUES (?, ?)', [$id, $owner]);
            $db->statement('UPDATE lab_endurance_tenant SET total = total + 1 WHERE id = ?', [$owner]);

            return ['tenant' => $owner, 'created' => true];
        });
        if ($state['tenant'] !== $owner) {
            return Response::json(['error' => 'tenant mismatch'], 409);
        }
        if ($state['created'] && $id === 'chaos-commit') {
            // Intentional post-commit fault window; supervisor kills this host before response.
            sleep(10);
        }
        ensure($this->app->make(CacheManager::class)->store('shared')->set('endurance.' . $id, $owner, 10800), 'Cache publication failed.');

        return Response::json(['id' => $id, ...$state], $state['created'] ? 201 : 200);
    }

    public function read(string $tenant, string $id): Response
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $owner = $db->scalar('SELECT tenant FROM lab_endurance_ledger WHERE id = ?', [$id]);
        if (!is_numeric($owner) || (int) $owner !== (int) $tenant) {
            return Response::json(['error' => 'not found'], 404);
        }
        ensure($this->app->make(CacheManager::class)->store('shared')->get('endurance.' . $id) === (int) $owner, 'Durable/cache mismatch.');

        return Response::json(['id' => $id, 'tenant' => (int) $owner]);
    }

    public function awaitCommitted(): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $deadline = hrtime(true) + 8_000_000_000;
        do {
            if (countValue($db->scalar('SELECT COUNT(*) FROM lab_endurance_ledger WHERE id = ?', ['chaos-commit'])) === 1) {
                return;
            }
            usleep(10000);
        } while (hrtime(true) < $deadline);
        throw new \RuntimeException('Fault injection never observed a durable commit.');
    }

    public function reconcile(): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $cache = $this->app->make(CacheManager::class)->store('shared');
        $totals = array_fill(1, 8, 0);
        foreach ($db->table('lab_endurance_ledger')->orderBy('id')->unbufferedStream(fetchSize: 256) as $row) {
            $tenant = (int) $row['tenant'];
            ensure(isset($totals[$tenant]) && $cache->get('endurance.' . $row['id']) === $tenant, 'Durable/cache reconciliation failed.');
            ++$totals[$tenant];
            echo $row['id'], ':', $tenant, PHP_EOL;
        }
        foreach ($db->select('SELECT id, total FROM lab_endurance_tenant') as $row) {
            ensure($totals[(int) $row['id']] === (int) $row['total'], 'Tenant aggregate diverged from ledger.');
        }
    }
}
