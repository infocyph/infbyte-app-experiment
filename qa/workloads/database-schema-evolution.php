<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\MigrationException;
use Infocyph\DBLayer\Exceptions\SchemaException;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

function schemaEvolutionSeed(Connection $db): SchemaManager
{
    $schema = new SchemaManager($db);
    $schema->create('lab_evolution', static function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->integer('tenant_id');
        $table->string('reference', 80);
        $table->string('annotation', 80)->nullable();
        $table->unique(['tenant_id', 'reference'], 'lab_evolution_unique');
        $table->index('reference', 'lab_evolution_lookup');
    });
    check($db->table('lab_evolution')->insert([
        ['id' => 1, 'tenant_id' => 10, 'reference' => 'shared', 'annotation' => 'first'],
        ['id' => 2, 'tenant_id' => 20, 'reference' => 'shared', 'annotation' => null],
    ]), 'Schema evolution seed failed.');

    return $schema;
}

function schemaEvolutionIds(Connection $db): array
{
    return array_map('intval', $db->table('lab_evolution')->orderBy('id')->pluck('id'));
}

function schemaEvolutionContracts(Connection $db, string $contract): void
{
    $schema = schemaEvolutionSeed($db);
    $peer = new Connection(config());
    $physical = new SchemaManager($peer);
    switch ($contract) {
        case 'column':
            $schema->table('lab_evolution', static function (Blueprint $table): void {
                $table->string('external_id', 80)->nullable();
            });
            check($physical->hasColumn('lab_evolution', 'external_id'), 'New column missing from independent connection.');
            check($peer->table('lab_evolution')->whereNull('external_id')->count() === 2, 'Nullable addition changed existing rows.');
            $db->table('lab_evolution')->where('id', '=', 1)->update(['external_id' => 'settled']);
            $schema->table('lab_evolution', static fn(Blueprint $table) => $table->renameColumn('external_id', 'settlement_id'));
            check(!$physical->hasColumn('lab_evolution', 'external_id') && $physical->hasColumn('lab_evolution', 'settlement_id'), 'Column rename visibility differs.');
            check($peer->table('lab_evolution')->find(1)['settlement_id'] === 'settled' && $peer->table('lab_evolution')->find(2)['settlement_id'] === null, 'Column rename lost stored data or null.');
            $schema->table('lab_evolution', static fn(Blueprint $table) => $table->dropColumn('settlement_id'));
            check(!$physical->hasColumn('lab_evolution', 'settlement_id') && schemaEvolutionIds($peer) === [1, 2], 'Column drop affected business rows.');
            break;
        case 'unique':
            $duplicate = ['id' => 3, 'tenant_id' => 10, 'reference' => 'shared', 'annotation' => null];
            rejected(static fn() => $db->table('lab_evolution')->insert($duplicate), 'Composite uniqueness did not reject duplicate.');
            check(schemaEvolutionIds($peer) === [1, 2], 'Rejected insert published data.');
            $schema->table('lab_evolution', static fn(Blueprint $table) => $table->dropUnique('lab_evolution_unique'));
            check($peer->table('lab_evolution')->insert($duplicate), 'Dropping unique index did not admit duplicate.');
            rejected(static fn() => $schema->table('lab_evolution', static fn(Blueprint $table) => $table->unique(['tenant_id', 'reference'], 'lab_evolution_unique')), 'Unique index creation accepted duplicate data.');
            check(schemaEvolutionIds($peer) === [1, 2, 3], 'Failed index creation changed rows.');
            $db->table('lab_evolution')->where('id', '=', 3)->delete();
            $schema->table('lab_evolution', static fn(Blueprint $table) => $table->unique(['tenant_id', 'reference'], 'lab_evolution_unique'));
            rejected(static fn() => $peer->table('lab_evolution')->insert($duplicate), 'Recreated unique index did not reject duplicate.');
            check(schemaEvolutionIds($peer) === [1, 2], 'Unique index repair lost data.');
            break;
        case 'index':
            $rename = static fn() => $schema->table('lab_evolution', static fn(Blueprint $table) => $table->renameIndex('lab_evolution_lookup', 'lab_evolution_new_lookup'));
            if ($db->getDriverName() === 'sqlite') {
                try {
                    $rename();
                    throw new LogicException('SQLite unsupported index rename succeeded.');
                } catch (SchemaException) {
                    // Explicit unsupported dialect operation, not a skipped check.
                }
                $schema->table('lab_evolution', static fn(Blueprint $table) => $table->dropIndex('lab_evolution_lookup'));
                $schema->table('lab_evolution', static fn(Blueprint $table) => $table->index('reference', 'lab_evolution_new_lookup'));
            } else {
                $rename();
                // The original name must now be free for a separate index.
                $schema->table('lab_evolution', static fn(Blueprint $table) => $table->index('reference', 'lab_evolution_lookup'));
                $schema->table('lab_evolution', static fn(Blueprint $table) => $table->dropIndex('lab_evolution_lookup'));
            }
            $schema->table('lab_evolution', static fn(Blueprint $table) => $table->dropIndex('lab_evolution_new_lookup'));
            $schema->table('lab_evolution', static fn(Blueprint $table) => $table->index('reference', 'lab_evolution_new_lookup'));
            check(schemaEvolutionIds($peer) === [1, 2], 'Index lifecycle changed rows.');
            break;
        case 'foreign-set-null':
        case 'foreign-drop':
            if ($db->getDriverName() === 'sqlite') {
                $db->getPdo()->exec('PRAGMA foreign_keys = ON');
                $peer->getPdo()->exec('PRAGMA foreign_keys = ON');
            }
            $schema->create('lab_evolution_child', static function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->integer('parent_id')->nullable();
                $table->foreign('parent_id', 'lab_evolution_fk')->references('id')->on('lab_evolution')->onDelete('set null');
            });
            check($db->table('lab_evolution_child')->insert([['id' => 1, 'parent_id' => 1], ['id' => 2, 'parent_id' => 2]]), 'Child seed failed.');
            $orphan = ['id' => 3, 'parent_id' => 99];
            rejected(static fn() => $peer->table('lab_evolution_child')->insert($orphan), 'Foreign key admitted orphan.');
            if ($contract === 'foreign-set-null') {
                $db->table('lab_evolution')->where('id', '=', 1)->delete();
                check($peer->table('lab_evolution_child')->find(1)['parent_id'] === null && (int) $peer->table('lab_evolution_child')->find(2)['parent_id'] === 2, 'SET NULL crossed parent ownership.');
                check($peer->table('lab_evolution_child')->count() === 2 && schemaEvolutionIds($peer) === [2], 'SET NULL deleted child or wrong parent.');
            } else {
                $drop = static fn() => $schema->table('lab_evolution_child', static fn(Blueprint $table) => $table->dropForeign('lab_evolution_fk'));
                if ($db->getDriverName() === 'sqlite') {
                    try {
                        $drop();
                        throw new LogicException('SQLite unsupported foreign drop succeeded.');
                    } catch (SchemaException) {
                        // The original foreign key must remain effective.
                    }
                    rejected(static fn() => $peer->table('lab_evolution_child')->insert($orphan), 'Rejected drop disabled foreign key.');
                    check($peer->table('lab_evolution_child')->count() === 2, 'Rejected drop changed child rows.');
                } else {
                    $drop();
                    check($peer->table('lab_evolution_child')->insert($orphan), 'Dropped foreign key still rejects orphan.');
                    $db->table('lab_evolution_child')->where('id', '=', 3)->delete();
                    $schema->table('lab_evolution_child', static fn(Blueprint $table) => $table->foreign('parent_id', 'lab_evolution_fk')->references('id')->on('lab_evolution')->onDelete('set null'));
                    rejected(static fn() => $peer->table('lab_evolution_child')->insert($orphan), 'Restored foreign key admitted orphan.');
                }
                check(schemaEvolutionIds($peer) === [1, 2] && $peer->table('lab_evolution_child')->count() === 2, 'Foreign constraint lifecycle changed business data.');
            }
            break;
        case 'destructive-guard':
            try {
                $schema->dropAllTables();
                throw new LogicException('Unapproved destructive operation succeeded.');
            } catch (MigrationException) {
                check($physical->hasTable('lab_evolution') && schemaEvolutionIds($peer) === [1, 2], 'Rejected destructive command changed database.');
            }
            $sql = $schema->toSql('lab_evolution', false, static fn(Blueprint $table) => $table->string('dry_run', 20)->nullable());
            check($sql !== [] && !$physical->hasColumn('lab_evolution', 'dry_run'), 'Schema compilation executed DDL.');
            $schema->table('lab_evolution', static fn(Blueprint $table) => $table->string('dry_run', 20)->nullable());
            check($physical->hasColumn('lab_evolution', 'dry_run') && schemaEvolutionIds($peer) === [1, 2], 'Explicit schema application did not recover.');
            $schema->dropIfExists('lab_nonexistent');
            check($physical->hasTable('lab_evolution'), 'Idempotent drop affected unrelated table.');
            break;
        default:
            throw new InvalidArgumentException('Unknown schema evolution contract.');
    }
    echo "Schema evolution $contract passed\n";
}
