<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

/*
 * Wave 0 RED stub for Req 11 (13.2-VALIDATION.md), mirroring
 * TransactionSplitsRegistryColumnsTest exactly. The `envelope_settings`
 * table (Plan 02) and its MergeRulesRegistry entry (Plan 05) do not exist
 * yet -- expected to fail on the missing table/empty registry entry, never a
 * parse error.
 *
 * Asserts requiredCreateColumns('envelope_settings') is a SUBSET of the
 * migration's actual NOT-NULL-without-default columns (excluding the
 * auto-increment primary key), and pins the exact expected set per
 * 13.2-PATTERNS.md § "MergeRulesRegistry extension" (D-25): user_id,
 * category_id, overspend_mode.
 */

it('MergeRulesRegistry envelope_settings _create_required is a subset of the real NOT-NULL-without-default columns', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $columns = $connection->getSchemaBuilder()->getColumns('envelope_settings');

    /** @var list<string> $notNullWithoutDefault */
    $notNullWithoutDefault = collect($columns)
        ->reject(static fn (array $col): bool => (bool) $col['auto_increment'])
        ->filter(static fn (array $col): bool => $col['nullable'] === false && $col['default'] === null)
        ->pluck('name')
        ->all();

    $registry = new MergeRulesRegistry;
    $required = $registry->requiredCreateColumns('envelope_settings');

    expect($required)->not->toBeEmpty();

    $missing = array_diff($required, $notNullWithoutDefault);
    expect($missing)->toBe([], 'every _create_required string must match a real NOT-NULL-without-default column: '.implode(', ', $missing));

    // Pin down the exact expected set per 13.2-PATTERNS.md § "MergeRulesRegistry
    // extension" (D-25). NOTE for Plan 02/05: `overspend_mode` carries a
    // `->default('reduce_to_budget')` in the suggested migration template,
    // which would fall OUT of the NOT-NULL-without-default set above. If
    // that tension surfaces, prefer dropping the DB-level default (the
    // application layer / D-12a already supplies the default for envelopes
    // with no settings row at all) so overspend_mode stays genuinely
    // required on every settings CreateRow op.
    expect($required)->toEqualCanonicalizing([
        'user_id',
        'category_id',
        'overspend_mode',
    ]);
});
