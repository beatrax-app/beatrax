<?php

declare(strict_types=1);

use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Tests\Contracts\Support\SyncedColumnWrites;

// The registry is not the wire filter: a dirty column a writer names travels
// whether or not it is declared, and an undeclared one merges as a plain Lww
// nobody chose. It is also invisible to the guard built from syncedColumns(),
// so the day its writer stops announcing it, nothing says so.
const EDITED_BUT_ONCE_UNDECLARED = [
    'anomaly_suppression_rules' => ['counterparty_id'],
    'counterparties' => ['iban', 'merchant_name', 'slug'],
    'goals' => ['status'],
    'pots' => ['category_id', 'goal_id', 'status'],
    'transaction_splits' => ['settled_currency', 'sort_order'],
];

it('declares every column an edit announces, so the announcement guard covers it', function (): void {
    $registry = new MergeRulesRegistry;
    $covered = SyncedColumnWrites::mergeableColumns($registry);

    $missing = [];

    foreach (EDITED_BUT_ONCE_UNDECLARED as $table => $columns) {
        foreach ($columns as $column) {
            if (! in_array($column, $covered[$table] ?? [], true)) {
                $missing[] = $table.'.'.$column;
            }
        }
    }

    expect($missing)->toBe([], 'A writer announces these on an edit. Undeclared, each merges as an Lww nobody chose and no guard can see it stop travelling. Offenders: '.implode(', ', $missing));
});

// The denominator. A coverage claim read off an empty set is not a claim, and
// mergeableColumns() skips the self-scoped and device-local tables, so an
// accidental empty return would pass the assertion above for every column.
it('reads a column set big enough to have found them missing', function (): void {
    $covered = SyncedColumnWrites::mergeableColumns(new MergeRulesRegistry);

    expect(count($covered))->toBeGreaterThan(20)
        ->and(array_sum(array_map('count', $covered)))->toBeGreaterThan(100);
});

// anomaly_suppression_rules declared NO mergeable field at all, which the
// authoring page reads as a deliberate statement that the table is
// insert-and-never-edited. A counterparty merge repoints the rule, so it is not.
it('does not read a repointed rule as an append-only ledger', function (): void {
    $registry = new MergeRulesRegistry;

    expect($registry->syncedColumns('anomaly_suppression_rules'))->toContain('counterparty_id')
        ->and(SyncedColumnWrites::mergeableColumns($registry))->toHaveKey('anomaly_suppression_rules');
});
