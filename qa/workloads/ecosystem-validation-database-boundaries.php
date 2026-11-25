<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\ReqShield\Bridge\DBLayerDatabaseProvider;
use Infocyph\ReqShield\CompiledValidator;
use Infocyph\ReqShield\Exceptions\DatabaseValidationException;
use Infocyph\ReqShield\Rule;
use Infocyph\ReqShield\Validator;

function validationBoundaryConnection(array $security = []): Connection
{
    $driver = (string) getenv('DB_BACKEND');
    $settings = match ($driver) {
        'sqlite' => ['driver' => 'sqlite', 'database' => evidenceDirectory() . '/validation-boundaries.sqlite'],
        'mssql' => ['driver' => 'mssql', 'host' => '127.0.0.1', 'port' => 1433,
            'database' => 'experiment', 'username' => 'sa', 'password' => 'Experiment-Only-42!',
            'encrypt' => true, 'trust_server_certificate' => true],
        'pgsql', 'mysql', 'mariadb' => ['driver' => $driver, 'host' => '127.0.0.1',
            'port' => match ($driver) { 'pgsql' => 5432, 'mariadb' => 3307, default => 3306 },
            'database' => 'experiment', 'username' => 'experiment', 'password' => 'experiment'],
        default => throw new InvalidArgumentException('Unsupported validation database.'),
    };

    return new Connection(ConnectionConfig::fromArray($settings + ['security' => $security]));
}

function validationBoundarySeed(Connection $db): void
{
    $db->statement('CREATE TABLE lab_validation_boundary (id INTEGER PRIMARY KEY, account_key VARCHAR(40) NULL, code VARCHAR(80) NULL, alias_code VARCHAR(80) NULL, archived_at VARCHAR(40) NULL)');
    $rows = [
        ['id' => 1, 'account_key' => 'owner', 'code' => 'shared', 'alias_code' => 'alias-only', 'archived_at' => null],
        ['id' => 2, 'account_key' => 'other', 'code' => 'shared', 'alias_code' => 'second', 'archived_at' => null],
        ['id' => 3, 'account_key' => 'owner', 'code' => 'owned', 'alias_code' => 'third', 'archived_at' => null],
        ['id' => 4, 'account_key' => null, 'code' => 'null-owner', 'alias_code' => 'fourth', 'archived_at' => null],
        ['id' => 5, 'account_key' => 'archived', 'code' => 'retired', 'alias_code' => 'fifth', 'archived_at' => '2025-01-01'],
        ['id' => 6, 'account_key' => 'empty', 'code' => null, 'alias_code' => 'sixth', 'archived_at' => null],
    ];
    for ($id = 7; $id <= 80; ++$id) {
        $rows[] = ['id' => $id, 'account_key' => 'account-' . $id, 'code' => 'code-' . $id,
            'alias_code' => 'alias-' . $id, 'archived_at' => null];
    }
    foreach (array_chunk($rows, 12) as $chunk) {
        verify($db->table('lab_validation_boundary')->insert($chunk), 'Validation seed insertion failed.');
    }
}

/** @param array<string,mixed> $data @param list<string> $fields */
function validationBoundaryErrors(Validator|CompiledValidator $validator, array $data, array $fields): void
{
    $result = $validator->validate($data);
    $actual = array_keys($result->errors());
    sort($actual);
    sort($fields);
    verify($actual === $fields && $result->passes() === ($fields === []), 'Concrete database error fields differ: ' . json_encode($actual, JSON_THROW_ON_ERROR));
}

function validationDatabaseBoundaryContracts(string $contract): void
{
    $db = validationBoundaryConnection();
    validationBoundarySeed($db);
    $table = 'lab_validation_boundary';
    $provider = DBLayerDatabaseProvider::fromConnection($db, 7);
    if ($contract === 'null-values') {
        // The low-level batch API explicitly accepts null candidates. The ordinary
        // validator can skip nullable fields, so exercise the provider directly.
        $checks = [
            ['id' => 1, 'field' => 'first', 'column' => 'code', 'value' => null],
            ['id' => 2, 'field' => 'second', 'column' => 'code', 'value' => 'missing'],
            ['id' => 3, 'field' => 'third', 'column' => 'code', 'value' => 'shared'],
            ['id' => 4, 'field' => 'fourth', 'column' => 'code', 'value' => null],
        ];
        $exists = $provider->batchExists($table, $checks);
        $unique = $provider->batchUnique($table, $checks);
        sort($exists);
        sort($unique);
        verify($exists === [2] && $unique === [1, 3, 4], 'Null or repeated-candidate correlation differs.');
        $db->table($table)->where('id', '=', 6)->update(['code' => 'restored']);
        $exists = $provider->batchExists($table, $checks);
        sort($exists);
        verify($exists === [1, 2, 4], 'Provider retained previous null match.');
    } elseif ($contract === 'identifier-guards') {
        $check = ['id' => 1, 'field' => 'code', 'column' => 'code', 'value' => 'shared'];
        foreach (['lab_validation_boundary; SELECT 1', 'lab_validation_boundary --', ''] as $bad) {
            expectFailure(static fn() => $provider->batchExists($bad, [$check]), InvalidArgumentException::class, 'Unsafe table identifier accepted.');
        }
        foreach (['code) OR 1=1', 'code --', ''] as $bad) {
            expectFailure(static fn() => $provider->batchExists($table, [array_replace($check, ['column' => $bad])]), InvalidArgumentException::class, 'Unsafe column identifier accepted.');
        }
        expectFailure(static fn() => $provider->batchUnique($table, [array_replace($check, ['ignore' => 'owner', 'id_column' => 'account_key; SELECT 1'])]), InvalidArgumentException::class, 'Unsafe identity column accepted.');
        expectFailure(static fn() => $provider->batchUnique($table, [array_replace($check, ['include_trashed' => false, 'soft_delete_column' => 'archived_at --'])]), InvalidArgumentException::class, 'Unsafe soft-delete column accepted.');
        verify($provider->batchExists($table, [$check]) === [], 'Valid lookup failed after identifier rejection.');
    } elseif ($contract === 'failure-recovery') {
        $builder = Validator::make(['code' => 'required|exists:lab_validation_boundary,code'], $provider);
        foreach ([$builder, new CompiledValidator($builder)] as $validator) {
            validationBoundaryErrors($validator, ['code' => 'shared'], []);
            $db->statement('DROP TABLE lab_validation_boundary');
            expectFailure(static fn() => $validator->validate(['code' => 'shared']), DatabaseValidationException::class, 'Database failure became invalid input.');
            validationBoundarySeed($db);
            validationBoundaryErrors($validator, ['code' => 'shared'], []);
            validationBoundaryErrors($validator, ['code' => 'missing'], ['code']);
        }
    } else {
        $restricted = $contract === 'restricted-policy';
        if ($restricted) {
            $provider = DBLayerDatabaseProvider::fromConnection(validationBoundaryConnection(['raw_sql_policy' => 'deny']), 7);
        }
        if ($contract === 'ignore-policy') {
            $base = Rule::unique($table, 'code');
            $owned = $base->idColumn('account_key')->ignore('owner');
            $active = $base->withoutTrashed('archived_at');
            $builder = Validator::make([
                'owned' => [$owned], 'shared' => [$owned], 'null_owner' => [$owned],
                'active' => [$active], 'all' => [$active->withTrashed()], 'original' => [$base],
            ], $provider)->setFailFast(false);
            foreach ([$builder, new CompiledValidator($builder)] as $validator) {
                validationBoundaryErrors($validator, [
                    'owned' => 'owned', 'shared' => 'shared', 'null_owner' => 'null-owner',
                    'active' => 'retired', 'all' => 'retired', 'original' => 'owned',
                ], ['shared', 'null_owner', 'all', 'original']);
                validationBoundaryErrors($validator, [
                    'owned' => 'owned', 'shared' => 'free1', 'null_owner' => 'free2',
                    'active' => 'retired', 'all' => 'free3', 'original' => 'free4',
                ], []);
            }
        } else {
            verify(in_array($contract, ['correlation', 'restricted-policy'], true), 'Unknown validation contract.');
            $builder = Validator::make([
                'codes.*' => 'required|exists:lab_validation_boundary,code',
                'aliases.*' => 'required|exists:lab_validation_boundary,alias_code',
                'new_codes.*' => 'required|unique:lab_validation_boundary,code',
            ], $provider)->setFailFast(false);
            $values = array_map(static fn(int $id): string => 'code-' . $id, range(7, 80));
            $aliases = array_map(static fn(int $id): string => 'alias-' . $id, range(7, 80));
            $fresh = array_map(static fn(int $id): string => 'new-' . $id, range(7, 80));
            $good = ['codes' => [...$values, 'shared', 'shared'], 'aliases' => $aliases, 'new_codes' => $fresh];
            $bad = $good;
            $bad['codes'][7] = 'alias-only';
            $bad['codes'][35] = "missing' OR '1'='1";
            $bad['aliases'][8] = 'shared';
            $bad['new_codes'][7] = 'shared';
            $bad['new_codes'][35] = 'shared';
            foreach ([$builder, new CompiledValidator($builder)] as $validator) {
                validationBoundaryErrors($validator, $good, []);
                validationBoundaryErrors($validator, $bad, ['codes.7', 'codes.35', 'aliases.8', 'new_codes.7', 'new_codes.35']);
                validationBoundaryErrors($validator, $good, []);
            }
        }
    }
    verify($db->table($table)->count() === 80, 'Validation mutated or lost persisted fixture data.');
    echo "ReqShield database $contract on " . $db->getDriverName() . " passed\n";
}
