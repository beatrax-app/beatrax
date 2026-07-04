<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

/*
 * Wave 0 RED stub for Req 11 (13.2-VALIDATION.md), mirroring
 * TransactionSplitsRegistryColumnsTest exactly. The `envelope_moves` table
 * (Plan 02) and its MergeRulesRegistry entry (Plan 05) do not exist yet --
 * expected to fail on the missing table/empty registry entry, never a parse
 * error.
 *
 * Asserts requiredCreateColumns('envelope_moves') is a SUBSET of the
 * migration's actual NOT-NULL-without-default columns (excluding the
 * auto-increment primary key), and pins the exact expected set per
 * 13.2-PATTERNS.md § "MergeRulesRegistry extension" (D-25): category_id,
 * counterpart_category_id, period_start, amount_minor, currency, kind.
 */

it('MergeRulesRegistry envelope_moves _create_required is a subset of the real NOT-NULL-without-default columns', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $columns = $connection->getSchemaBuilder()->getColumns('envelope_moves');

    /** @var list<string> $notNullWithoutDefault */
    $notNullWithoutDefault = collect($columns)
        ->reject(static fn (array $col): bool => (bool) $col['auto_increment'])
        ->filter(static fn (array $col): bool => $col['nullable'] === false && $col['default'] === null)
        ->pluck('name')
        ->all();

    $registry = new MergeRulesRegistry;
    $required = $registry->requiredCreateColumns('envelope_moves');

    expect($required)->not->toBeEmpty();

    $missing = array_diff($required, $notNullWithoutDefault);
    expect($missing)->toBe([], 'every _create_required string must match a real NOT-NULL-without-default column: '.implode(', ', $missing));

    // Pin down the exact expected set per 13.2-PATTERNS.md § "MergeRulesRegistry
    // extension" (D-25). NOTE for Plan 02/05: `counterpart_category_id` is
    // nullable and `currency` carries a `->default('EUR')` in the suggested
    // migration template -- either would fall OUT of the NOT-NULL-without-
    // default set above. If that tension surfaces, prefer making both
    // genuinely required at the DB layer (v1 is EUR-only and every move has
    // a real counterpart category, D-02) rather than dropping them from
    // `_create_required` and leaving them implicit on replay.
    expect($required)->toEqualCanonicalizing([
        'category_id',
        'counterpart_category_id',
        'period_start',
        'amount_minor',
        'currency',
        'kind',
    ]);
});
