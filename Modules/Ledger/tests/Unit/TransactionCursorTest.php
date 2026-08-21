<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Ledger\Public\Services\TransactionCursor;

// The sort and the row-value comparison are one contract: the cursor pages on
// (posted_at, id) descending, so a list that orders on posted_at alone, or that
// breaks the tie ascending, hands back a boundary row the cursor then skips.
// Four queries used to spell the sort out for themselves.

it('orders on the pair the cursor compares against, both descending', function (): void {
    $query = DB::connection()->table('transactions');
    TransactionCursor::orderNewestFirst($query);

    expect($query->toSql())->toContain('order by "transactions"."posted_at" desc, "transactions"."id" desc');
});

it('emits the sort and the comparison against the same two columns', function (): void {
    $query = DB::connection()->table('transactions');
    TransactionCursor::orderNewestFirst($query);
    TransactionCursor::apply($query, '2026-05-15', 42);

    expect($query->toSql())
        ->toContain('(transactions.posted_at, transactions.id) < (?, ?)')
        ->toContain('order by "transactions"."posted_at" desc, "transactions"."id" desc')
        ->and($query->getBindings())->toBe(['2026-05-15', 42]);
});

// Placement in the fluent chain is not part of the contract; the three callers
// that moved the two calls out of their chains have to emit what they did.
it('emits the same sql whether the sort is chained or applied afterwards', function (): void {
    $chained = DB::connection()->table('transactions')
        ->where('transactions.user_id', 1)
        ->orderByDesc('transactions.posted_at')
        ->orderByDesc('transactions.id')
        ->select(['transactions.id'])
        ->limit(51);

    $applied = DB::connection()->table('transactions')
        ->where('transactions.user_id', 1)
        ->select(['transactions.id'])
        ->limit(51);
    TransactionCursor::orderNewestFirst($applied);

    expect($applied->toSql())->toBe($chained->toSql());
});

it('leaves the sort alone when there is no cursor to page from', function (): void {
    $query = DB::connection()->table('transactions');
    TransactionCursor::orderNewestFirst($query);
    TransactionCursor::apply($query, null, null);

    expect($query->toSql())->not->toContain('where')
        ->and($query->toSql())->toContain('order by');
});
