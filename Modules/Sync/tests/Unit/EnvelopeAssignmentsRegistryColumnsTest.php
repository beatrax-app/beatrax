<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

/*
 * Wave 0 RED stub for Req 11 (13.2-VALIDATION.md), mirroring
 * TransactionSplitsRegistryColumnsTest exactly. The `envelope_assignments`
 * table (Plan 02) and its MergeRulesRegistry entry (Plan 05) do not exist
 * yet -- expected to fail on the missing table/empty registry entry, never a
 * parse error.
 *
 * Asserts requiredCreateColumns('envelope_assignments') is a SUBSET of the
 * migration's actual NOT-NULL-without-default columns (excluding the
 * auto-increment primary key), and pins the exact expected set per
 * 13.2-PATTERNS.md § "MergeRulesRegistry extension" (D-25):
 * user_id, category_id, period_start, assigned_minor, currency.
 */

it('MergeRulesRegistry envelope_assignments _create_required is a subset of the real NOT-NULL-without-default columns', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $columns = $connection->getSchemaBuilder()->getColumns('envelope_assignments');

    /** @var list<string> $notNullWithoutDefault */
    $notNullWithoutDefault = collect($columns)
        ->reject(static fn (array $col): bool => (bool) $col['auto_increment'])
        ->filter(static fn (array $col): bool => $col['nullable'] === false && $col['default'] === null)
        ->pluck('name')
        ->all();

    $registry = new MergeRulesRegistry;
    $required = $registry->requiredCreateColumns('envelope_assignments');

    expect($required)->not->toBeEmpty();

    $missing = array_diff($required, $notNullWithoutDefault);
    expect($missing)->toBe([], 'every _create_required string must match a real NOT-NULL-without-default column: '.implode(', ', $missing));

    // Pin down the exact expected set per 13.2-PATTERNS.md § "MergeRulesRegistry
    // extension" (D-25). NOTE for Plan 02/05: PATTERNS.md's migration template
    // gives `currency` a `->default('EUR')`, which would make it fall OUT of
    // the NOT-NULL-without-default set above and break this exact assertion --
    // if that tension surfaces, drop the DB-level default on `currency` (v1 is
    // EUR-only anyway, D-25) so it stays a genuinely required create column,
    // rather than removing it from `_create_required` and leaving the assign
    // op's currency implicit.
    expect($required)->toEqualCanonicalizing([
        'user_id',
        'category_id',
        'period_start',
        'assigned_minor',
        'currency',
    ]);
});
