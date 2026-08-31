<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

// A _create_required string that does not match a real migration column
// silently quarantines every CreateRow op for the table, and a
// monthly_limit_minor/budget_minor typo is all it takes.

it('MergeRulesRegistry transaction_splits _create_required is a subset of the real NOT-NULL-without-default columns', function (): void {
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

    // user_id and note are nullable, sort_order has a default and the timestamps
    // are nullable, so none of them belong in _create_required.
    expect($required)->toEqualCanonicalizing([
        'transaction_id',
        'category_id',
        'settled_amount_minor',
        'settled_currency',
    ]);
});
