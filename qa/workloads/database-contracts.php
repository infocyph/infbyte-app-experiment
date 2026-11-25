<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\TransactionException;

function predicateContracts(Connection $db): void
{
    seedRows($db, 20);
    $db->table('lab_records')->whereIn('id', [2, 4, 6])->update(['deleted_at' => '2026-01-01']);
    $query = $db->table('lab_records')->where('tenant_id', '=', 1);
    $ids = static fn($builder): array => array_map('intval', $builder->orderBy('id')->pluck('id'));
    check($ids($query->cloneBuilder()->whereIn('id', [])) === [], 'Empty IN selected rows.');
    check($ids($query->cloneBuilder()->whereNotIn('id', [])) === range(2, 20, 2), 'Empty NOT IN removed rows.');
    check($ids($query->cloneBuilder()->whereNotNull('deleted_at')) === [2, 4, 6], 'NOT NULL projection diverged.');
    check($ids($query->cloneBuilder()->whereNull('deleted_at')) === range(8, 20, 2), 'NULL projection diverged.');
    check($ids($query->cloneBuilder()->whereNotBetween('amount', [2, 6])) === [8, 10, 18, 20], 'NOT BETWEEN changed semantics.');
    $conditional = $query->cloneBuilder()
        ->when(false, static fn($q) => $q->where('id', '=', 1), static fn($q) => $q->whereNotIn('id', [2, 4]))
        ->unless(false, static fn($q) => $q->whereNull('deleted_at'));
    check($ids($conditional) === range(8, 20, 2) && $query->count() === 10, 'Conditional branch mutated base or escaped tenant.');
    $db->statement('CREATE TABLE lab_flags (record_id INTEGER PRIMARY KEY)');
    $db->table('lab_flags')->insert([['record_id' => 4], ['record_id' => 7]]);
    $exists = static fn($q) => $q->from('lab_flags')->select('record_id')->whereRaw('lab_flags.record_id = lab_records.id');
    check($ids($query->cloneBuilder()->whereExists($exists)) === [4], 'Correlated EXISTS crossed tenant.');
    check($ids($query->cloneBuilder()->whereExists($exists, not: true)) === [2, 6, 8, 10, 12, 14, 16, 18, 20], 'NOT EXISTS diverged.');
    check($db->table('lab_records')->count() === 20, 'Predicate queries mutated durable data.');
    echo "NULL, empty lists, negation, conditional branch and correlated existence passed\n";
}

function paginationContracts(Connection $db): void
{
    seedRows($db, 46);
    $base = $db->table('lab_records')->where('tenant_id', '=', 1)->orderBy('id');
    $expected = range(2, 46, 2);
    foreach ([1, 2, 3, 4, 5] as $page) {
        $rows = array_slice($expected, ($page - 1) * 7, 7);
        $length = $base->paginate(7, $page);
        $simple = $base->simplePaginate(7, $page);
        foreach ([$length, $simple] as $result) {
            check(array_map('intval', array_column($result->items(), 'id')) === $rows, 'Page ordering or tenant scope changed.');
            check($result->count() === count($rows) && $result->currentPage() === $page && $result->perPage() === 7, 'Page size or index mismatch.');
            check($result->firstItem() === ($rows === [] ? null : ($page - 1) * 7 + 1) && $result->lastItem() === ($rows === [] ? null : ($page - 1) * 7 + count($rows)), 'Page item boundaries diverged.');
            check($result->hasMorePages() === ($page < 4), 'Next-page indicator mismatch.');
            check(iterator_to_array($result) === $result->items() && $result->toArray()['meta'] === $result->meta(), 'Paginator projection mismatch.');
        }
        check($length->total() === 23 && $length->lastPage() === 4 && $simple->total() === null && $simple->lastPage() === null, 'Count-aware/simple pagination contract mismatch.');
    }
    check($base->count() === 23 && array_map('intval', $base->pluck('id')) === $expected, 'Pagination mutated original query.');
    $empty = $base->cloneBuilder()->where('id', '=', -1)->paginate(7);
    check($empty->total() === 0 && $empty->lastPage() === 1 && !$empty->hasMorePages() && $empty->items() === [], 'Empty paginator contract mismatch.');
    rejected(static fn() => $base->paginate(0), 'Invalid page size accepted.');
    echo "Exact offset/simple pagination, metadata, empty pages, iteration and builder isolation passed\n";
}

function chunkCheckpoint(Connection $db): void
{
    seedRows($db, 124);
    $db->statement('CREATE TABLE lab_chunk_effects (id INTEGER PRIMARY KEY)');
    $db->statement('CREATE TABLE lab_chunk_checkpoint (id INTEGER PRIMARY KEY, last_id INTEGER NOT NULL)');
    $db->table('lab_chunk_checkpoint')->insert(['id' => 1, 'last_id' => 0]);
    $base = $db->table('lab_records')->where('tenant_id', '=', 1);
    $seen = [];
    $finished = $base->cloneBuilder()->orderBy('id')->chunk(11, static function (array $rows, int $page) use (&$seen): bool {
        array_push($seen, ...array_map('intval', array_column($rows, 'id')));
        return $page < 2;
    });
    check(!$finished && $seen === range(2, 44, 2), 'Offset early-stop boundary failed.');
    $processed = $base->chunkById(9, static function (array $rows, int $page) use ($db): bool {
        $db->transaction(static function (Connection $active) use ($rows): void {
            foreach ($rows as $row) {
                $active->table('lab_chunk_effects')->insert(['id' => (int) $row['id']]);
            }
            $active->table('lab_chunk_checkpoint')->where('id', '=', 1)->update(['last_id' => (int) $rows[array_key_last($rows)]['id']]);
        });
        return $page < 2;
    });
    check(!$processed && $db->table('lab_chunk_effects')->count() === 18, 'Keyset partial commit mismatch.');
    $fresh = new Connection(config());
    $checkpoint = (int) $fresh->table('lab_chunk_checkpoint')->where('id', '=', 1)->value('last_id');
    check($checkpoint === 36, 'Durable checkpoint mismatch.');
    foreach ($fresh->table('lab_records')->where('tenant_id', '=', 1)->lazyById(7, fromId: $checkpoint) as $row) {
        $fresh->table('lab_chunk_effects')->insert(['id' => (int) $row['id']]);
    }
    check(array_map('intval', $fresh->table('lab_chunk_effects')->orderBy('id')->pluck('id')) === range(2, 124, 2), 'Resumed iteration lost or duplicated effects.');
    $descending = [];
    foreach ($base->lazy(13, fromId: 101, direction: 'desc') as $row) {
        $descending[] = (int) $row['id'];
    }
    check($descending === range(100, 2, -2) && $base->count() === 62, 'Descending keyset checkpoint or builder isolation failed.');
    echo "Offset early stop, durable keyset checkpoint, replacement and descending lazy traversal passed\n";
}

function connectionReuseContracts(Connection $db): void
{
    setup($db);
    $before = balance($db, 1);
    $logged = $db->pretend(static function (Connection $active): void {
        $active->update('UPDATE lab_balances SET balance = ? WHERE id = ?', [999, 1]);
        $active->select('SELECT balance FROM lab_balances WHERE id = ?', [2]);
    });
    check(count($logged) === 2 && $logged[0]['bindings'] === [999, 1] && $logged[1]['bindings'] === [2] && balance($db, 1) === $before, 'Pretend executed SQL or lost binding evidence.');
    try {
        $db->pretend(static function (Connection $active): never {
            $active->update('UPDATE lab_balances SET balance = ? WHERE id = ?', [888, 1]);
            throw new DomainException('fixture failure');
        });
        throw new LogicException('Pretend swallowed exception.');
    } catch (DomainException) {
        check(balance($db, 1) === $before, 'Failed pretend changed data.');
    }
    $db->setQueryCommentContext(['tenant' => 'alpha', 'enabled' => true])->mergeQueryCommentContext(['tenant' => 'beta', 'request' => 'fixture']);
    check($db->getQueryCommentContext()['tenant'] === 'beta' && $db->getQueryCommentContext()['enabled'] === 'true', 'Comment context normalization or merge failed.');
    $db->setQueryTimeoutMs(5000)->setFetchMode(PDO::FETCH_NUM);
    check($db->resetRuntimeStateForReuse() && $db->getQueryTimeoutMs() === null && $db->getQueryCommentContext() === [] && $db->getFetchMode() === PDO::FETCH_ASSOC, 'Request state survived connection reuse.');
    $events = [];
    $db->beforeReconnect(static function (Connection $conn, bool $write, int $attempt) use (&$events): void { $events[] = ['before', $write, $attempt]; });
    $db->afterReconnect(static function (Connection $conn, bool $write, int $attempt) use (&$events): void { $events[] = ['after', $write, $attempt]; });
    $db->reconnect(true);
    check($events === [['before', true, 1], ['after', true, 1]], 'Reconnect lifecycle hooks diverged.');
    $db->table('lab_balances')->where('id', '=', 1)->update(['balance' => 105]);
    check(balance(new Connection(config()), 1) === 105, 'Pretend/reuse/reconnect prevented subsequent real write.');
    echo "Pretend success/failure, request reset, reconnect hook order and durable write passed\n";
}

function batchBoundaries(Connection $db): void
{
    $db->statement('CREATE TABLE lab_batches (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, amount INTEGER NOT NULL)');
    $bounded = new Connection(config(['security' => ['max_params' => 31]]));
    check($bounded->effectiveMaxBindParameters() === 31 && $bounded->safeBatchSize(3, 1) === 10 && $bounded->safeBatchSize(3, 1, 4) === 4, 'Effective parameter budget mismatch.');
    rejected(static fn() => $bounded->safeBatchSize(3, 29), 'Impossible fixed binding budget accepted.');
    $rows = array_map(static fn(int $id): array => ['id' => $id, 'tenant_id' => 1, 'amount' => $id * 2], range(1, 103));
    $chunks = array_chunk($rows, $bounded->safeBatchSize(3));
    $effects = 0;
    $bounded->transaction(static function (Connection $active) use ($chunks, &$effects): void {
        foreach ($chunks as $chunk) {
            check($active->table('lab_batches')->insert($chunk), 'Bounded insert failed.');
        }
        $active->afterCommit(static function () use (&$effects): void { ++$effects; });
        check($effects === 0, 'Batch callback ran before commit.');
    });
    check($effects === 1 && $bounded->transactionLevel() === 0, 'Batch transaction did not settle once.');
    try {
        $bounded->transaction(static function (Connection $active) use (&$effects): void {
            $active->table('lab_batches')->insert(['id' => 104, 'tenant_id' => 1, 'amount' => 208]);
            $active->afterCommit(static function () use (&$effects): void { ++$effects; });
            throw new DomainException('fixture rollback');
        });
        throw new LogicException('Rollback failure was swallowed.');
    } catch (TransactionException $failure) {
        check($failure->getPrevious() instanceof DomainException, 'Unexpected rollback reason.');
    }
    $fresh = new Connection(config());
    check($effects === 1 && $bounded->transactionLevel() === 0 && $fresh->table('lab_batches')->count() === 103
        && (int) $fresh->table('lab_batches')->sum('amount') === 10712 && !$fresh->table('lab_batches')->where('id', '=', 104)->exists(), 'Batch durable reconciliation or rollback failed.');
    echo "Parameter budget, eleven bounded batches, commit callbacks and rollback reconciliation passed\n";
}
