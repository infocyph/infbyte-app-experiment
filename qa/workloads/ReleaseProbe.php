<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Webrick\Response\Response;

final readonly class ReleaseProbe
{
    public function __construct(private Application $app) {}

    public function old(): Response
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        return Response::json(['generation' => 'old', 'value' => (int) $db->scalar('SELECT value FROM lab_release_data WHERE id = 1')]);
    }

    public function next(): Response
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $row = $db->select('SELECT value, revision FROM lab_release_data WHERE id = 1')[0];

        return Response::json(['generation' => 'next', 'value' => (int) $row['value'], 'revision' => (int) $row['revision']]);
    }

    public function setup(): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $db->statement('CREATE TABLE lab_release_data (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');
        $db->statement('INSERT INTO lab_release_data (id, value) VALUES (1, 42)');
    }

    public function migrate(): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $db->statement('ALTER TABLE lab_release_data ADD COLUMN revision INTEGER NOT NULL DEFAULT 1');
    }
}
