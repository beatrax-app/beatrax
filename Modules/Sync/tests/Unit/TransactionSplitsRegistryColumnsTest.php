<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

/*
 * 13.1-03 Task 1 (T-13.1-08): generalizes MoneyColumnsArchTest/UserIdColumnArchTest's
 * schema-introspection style to guard against the exact category_budgets
 * monthly_limit_minor/budget_minor typo — a _create_required string that does
 * not match a real migration column silently quarantines every CreateRow op
 * for the table (OpLogReplayer::requiredCreateColumns, incomplete_create_row).
 *
 * Asserts requiredCreateColumns('transaction_splits') is a SUBSET of the
 * migration's actual NOT-NULL-without-default columns (excluding the
 * auto-increment primary key).
 */

it('MergeRulesRegistry transaction_splits _create_required is a subset of the real NOT-NULL-without-default columns', function (): void {
    // RefreshDatabase has already migrated the test schema; introspect it directly.
    $connection = app(DatabaseManager::class)->connection();
    $columns = $connection->getSchemaBuilder()->getColumns('transaction_splits');

    /** @var list<string> $notNullWithoutDefault */
    $notNullWithoutDefault = collect($columns)
        ->reject(static fn (array $col): bool => (bool) $col['auto_increment'])
        ->filter(static fn (array $col): bool => $col['nullable'] === false && $col['default'] === null)
        ->pluck('name')
        ->all();

    $registry = new MergeRulesRegistry;
    $required = $registry->requiredCreateColumns('transaction_splits');

    expect($required)->not->toBeEmpty();

    $missing = array_diff($required, $notNullWithoutDefault);
    expect($missing)->toBe([], 'every _create_required string must match a real NOT-NULL-without-default column: '.implode(', ', $missing));

    // Pin down the exact expected set per the migration (transaction_id,
    // category_id, settled_amount_minor, settled_currency) — user_id is
    // nullable, note is nullable, sort_order has a default, timestamps are
    // nullable — none of those belong in _create_required.
    expect($required)->toEqualCanonicalizing([
        'transaction_id',
        'category_id',
        'settled_amount_minor',
        'settled_currency',
    ]);
});
