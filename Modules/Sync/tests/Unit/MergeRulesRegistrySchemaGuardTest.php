<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

/*
 * Universal companion to the 7 per-table *ColumnsTest files. Those pin the
 * exact expected column set for one table each (sharper failures); this one
 * guards EVERY table in MergeRulesRegistry::rules() against two failure modes
 * that both silently break replication:
 *
 *   (a) EXISTENCE — a strategy-key column or a `_create_required` string that
 *       does not match a real migrated column. A phantom name in
 *       `_create_required` makes OpLogReplayer quarantine 100% of that table's
 *       CreateRow ops (incomplete_create_row); a phantom strategy key is dead
 *       config that will misroute a future SET op's merge strategy.
 *
 *   (b) SUBSET — `requiredCreateColumns($table)` must be a subset of the
 *       table's NOT-NULL-without-default columns (excluding the auto-increment
 *       PK). A NOT-NULL-with-default column (e.g. status default 'active')
 *       does not belong in `_create_required` — the row inserts fine without it.
 *
 * This is the oracle: registry columns must match the real schema. Never
 * weaken this test to make the registry pass — fix the registry.
 */

/**
 * @return list<string>
 */
function referencedColumnsFor(array $tableRules): array
{
    // Strategy-key columns: every top-level key that is NOT a '_'-prefixed
    // control key ('_delete_wins', '_create_required').
    $strategyKeys = array_values(array_filter(
        array_keys($tableRules),
        static fn (string $key): bool => ! str_starts_with($key, '_'),
    ));

    /** @var list<string> $createRequired */
    $createRequired = $tableRules['_create_required'] ?? [];

    return array_values(array_unique([...$strategyKeys, ...$createRequired]));
}

it('MergeRulesRegistry references only real columns and keeps _create_required a NOT-NULL-without-default subset for every registered table', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $schemaBuilder = $connection->getSchemaBuilder();

    $registry = new MergeRulesRegistry;
    $rules = $registry->rules();

    expect($rules)->not->toBeEmpty();

    /** @var array<string, list<string>> $existenceFailures */
    $existenceFailures = [];
    /** @var array<string, list<string>> $subsetFailures */
    $subsetFailures = [];

    foreach ($rules as $table => $tableRules) {
        $columns = $schemaBuilder->getColumns($table);

        /** @var list<string> $realColumns */
        $realColumns = collect($columns)->pluck('name')->all();

        // (a) EXISTENCE — every referenced column must be a real column.
        $referenced = referencedColumnsFor($tableRules);
        $phantom = array_values(array_diff($referenced, $realColumns));
        if ($phantom !== []) {
            $existenceFailures[$table] = $phantom;
        }

        // (b) SUBSET — _create_required ⊆ NOT-NULL-without-default (no PK).
        /** @var list<string> $notNullWithoutDefault */
        $notNullWithoutDefault = collect($columns)
            ->reject(static fn (array $col): bool => (bool) $col['auto_increment'])
            ->filter(static fn (array $col): bool => $col['nullable'] === false && $col['default'] === null)
            ->pluck('name')
            ->all();

        $required = $registry->requiredCreateColumns($table);
        $notSubset = array_values(array_diff($required, $notNullWithoutDefault));
        if ($notSubset !== []) {
            $subsetFailures[$table] = $notSubset;
        }
    }

    $renderFailures = static function (array $failures): string {
        $lines = [];
        foreach ($failures as $table => $cols) {
            $lines[] = sprintf('%s => [%s]', $table, implode(', ', $cols));
        }

        return implode('; ', $lines);
    };

    expect($existenceFailures)->toBe(
        [],
        'MergeRulesRegistry references phantom columns (no matching migrated column): '.$renderFailures($existenceFailures),
    );

    expect($subsetFailures)->toBe(
        [],
        'MergeRulesRegistry _create_required contains columns that are not NOT-NULL-without-default (they will be dropped or are optional): '.$renderFailures($subsetFailures),
    );
});
