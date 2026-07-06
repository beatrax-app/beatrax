<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

/*
 * Mirrors EnvelopeAssignmentsRegistryColumnsTest / TransactionSplitsRegistryColumnsTest
 * exactly. Asserts requiredCreateColumns('migration_source_map') is a SUBSET
 * of the migration's actual NOT-NULL-without-default columns (excluding the
 * auto-increment primary key), and pins the exact expected set per
 * 13.5-01-PLAN.md Task 3: source_product, source_entity_type,
 * beatrax_entity_type, beatrax_id. `user_id`/`source_external_id`/
 * `natural_key` are nullable (D-10 fallback + multi-user readiness) and
 * `created_at`/`updated_at` carry Laravel's default timestamp nullability, so
 * none of the four belong in this required set.
 */

it('MergeRulesRegistry migration_source_map _create_required is a subset of the real NOT-NULL-without-default columns', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $columns = $connection->getSchemaBuilder()->getColumns('migration_source_map');

    /** @var list<string> $notNullWithoutDefault */
    $notNullWithoutDefault = collect($columns)
        ->reject(static fn (array $col): bool => (bool) $col['auto_increment'])
        ->filter(static fn (array $col): bool => $col['nullable'] === false && $col['default'] === null)
        ->pluck('name')
        ->all();

    $registry = new MergeRulesRegistry;
    $required = $registry->requiredCreateColumns('migration_source_map');

    expect($required)->not->toBeEmpty();

    $missing = array_diff($required, $notNullWithoutDefault);
    expect($missing)->toBe([], 'every _create_required string must match a real NOT-NULL-without-default column: '.implode(', ', $missing));

    expect($required)->toEqualCanonicalizing([
        'source_product',
        'source_entity_type',
        'beatrax_entity_type',
        'beatrax_id',
    ]);
});
