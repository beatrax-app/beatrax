<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

/*
 * Mirrors EnvelopeAssignmentsRegistryColumnsTest / TransactionSplitsRegistryColumnsTest
 * exactly. Asserts requiredCreateColumns('migration_import_baseline') is a
 * SUBSET of the migration's actual NOT-NULL-without-default columns
 * (excluding the auto-increment primary key), and pins the exact expected
 * set per 13.5-01-PLAN.md Task 3: migration_source_map_id, field_name,
 * baseline_value, imported_at. `user_id` is nullable (multi-user readiness)
 * so it is deliberately excluded from this required set.
 */

it('MergeRulesRegistry migration_import_baseline _create_required is a subset of the real NOT-NULL-without-default columns', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $columns = $connection->getSchemaBuilder()->getColumns('migration_import_baseline');

    /** @var list<string> $notNullWithoutDefault */
    $notNullWithoutDefault = collect($columns)
        ->reject(static fn (array $col): bool => (bool) $col['auto_increment'])
        ->filter(static fn (array $col): bool => $col['nullable'] === false && $col['default'] === null)
        ->pluck('name')
        ->all();

    $registry = new MergeRulesRegistry;
    $required = $registry->requiredCreateColumns('migration_import_baseline');

    expect($required)->not->toBeEmpty();

    $missing = array_diff($required, $notNullWithoutDefault);
    expect($missing)->toBe([], 'every _create_required string must match a real NOT-NULL-without-default column: '.implode(', ', $missing));

    expect($required)->toEqualCanonicalizing([
        'migration_source_map_id',
        'field_name',
        'baseline_value',
        'imported_at',
    ]);
});
