<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Ledger\Public\Support\SplitLegs;

// No rows are needed: nothing in the app runs ANALYZE, so SQLite plans these
// from the schema alone and answers the same way on an empty install as on a
// full one.
function uncategorizedCountPlan(DatabaseManager $db, bool $withCategoryFilter): string
{
    $query = $db->connection()->table('transactions')->where('user_id', 1);

    if ($withCategoryFilter) {
        $query->whereNull('category_id');
    }

    $query->tap(static fn (Builder $q): Builder => SplitLegs::excludeParents($q));

    return implode("\n", array_map(
        static fn (object $row): string => (string) ($row->detail ?? ''),
        $db->connection()->select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings()),
    ));
}

it('indexes the uncategorized rows on the predicate the count filters on', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $row = $db->connection()->selectOne(
        "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'transactions_uncategorized_idx'",
    );

    $sql = is_object($row) && isset($row->sql) ? (string) $row->sql : '';

    expect($sql)->toContain('WHERE category_id IS NULL');
});

// Both directions, because naming the index proves nothing on its own: without
// the predicate it is a plain (user_id, posted_at) index and the planner picks
// it for a query with no category filter at all, having read every row.
it('seeks that index for the count and some other index without the filter', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect(uncategorizedCountPlan($db, withCategoryFilter: true))
        ->toContain('SEARCH transactions USING INDEX transactions_uncategorized_idx')
        ->and(uncategorizedCountPlan($db, withCategoryFilter: false))
        ->not->toContain('transactions_uncategorized_idx');
});
