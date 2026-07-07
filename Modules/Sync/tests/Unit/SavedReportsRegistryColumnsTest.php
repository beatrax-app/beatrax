<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

/*
 * Contract test for Req 9/10 (999.6-01-PLAN.md), mirroring
 * EnvelopeSettingsRegistryColumnsTest exactly.
 *
 * Asserts requiredCreateColumns('saved_reports') is a SUBSET of the
 * migration's actual NOT-NULL-without-default columns (excluding the
 * auto-increment primary key), and pins the exact expected set per
 * 999.6-PATTERNS.md § "MergeRulesRegistry.php (add saved_reports entry)"
 * (D-02, Pitfall 5): name, definition. `pinned` carries a DB-level
 * default(false) so it is deliberately excluded even though NOT NULL;
 * `pin_order` is nullable.
 */

it('MergeRulesRegistry saved_reports _create_required is a subset of the real NOT-NULL-without-default columns', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $columns = $connection->getSchemaBuilder()->getColumns('saved_reports');

    /** @var list<string> $notNullWithoutDefault */
    $notNullWithoutDefault = collect($columns)
        ->reject(static fn (array $col): bool => (bool) $col['auto_increment'])
        ->filter(static fn (array $col): bool => $col['nullable'] === false && $col['default'] === null)
        ->pluck('name')
        ->all();

    $registry = new MergeRulesRegistry;
    $required = $registry->requiredCreateColumns('saved_reports');

    expect($required)->not->toBeEmpty();

    $missing = array_diff($required, $notNullWithoutDefault);
    expect($missing)->toBe([], 'every _create_required string must match a real NOT-NULL-without-default column: '.implode(', ', $missing));

    expect($required)->toEqualCanonicalizing(['name', 'definition']);
});
