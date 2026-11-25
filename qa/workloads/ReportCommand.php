<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Command\CommandContext;
use Infocyph\Foundation\Command\CommandDefinition;
use Infocyph\Foundation\Command\CommandHandlerInterface;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class ReportCommand implements CommandHandlerInterface
{
    public function __construct(private Application $app) {}

    #[\Override]
    public static function define(CommandDefinition $command): void
    {
        $command->name('lab:report')->description('Reconcile the isolated experiment order ledger')->capability('database')->capability('cache')->scope();
    }

    /** @throws \Throwable */
    #[\Override]
    public function run(CommandContext $context): int
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $db->statement('INSERT INTO experiment_reports (id, amount) SELECT ?, SUM(amount) FROM experiment_orders', ['daily']);
        // Keep the native shared scheduler lease held long enough for peers
        // to contend; this deliberate delay is not a throughput measurement.
        sleep(3);
        $context->io()->success('Reconciled report.');

        return 0;
    }
}
