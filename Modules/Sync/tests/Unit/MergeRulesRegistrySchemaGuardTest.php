<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\RowOwnership;

uses(RefreshDatabase::class);

// A phantom name in `_create_required` makes OpLogReplayer quarantine every one
// of that table's CreateRow ops, and a phantom strategy key is dead config that
// will misroute a future set. The registry must match the real schema, so a
// failure here is fixed in the registry and never by weakening the test.

// OpLogEntryApplier::buildCreatePayload writes both of these itself — `id` from
// the op's own pk, `user_id` from the session scope — so a create is never
// incomplete for want of them and neither has to be sent.
/**
 * @return list<string>
 */
function applierSeededColumns(): array
{
    return ['id', 'user_id'];
}

/**
 * @return list<string>
 */
function referencedColumnsFor(array $tableRules): array
{
    // The '_'-prefixed keys are control keys, not columns.
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

        $referenced = referencedColumnsFor($tableRules);
        $phantom = array_values(array_diff($referenced, $realColumns));
        if ($phantom !== []) {
            $existenceFailures[$table] = $phantom;
        }

        // A NOT-NULL column that carries a default inserts fine without being
        // sent, so it does not belong in _create_required.
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

// The other direction, and the one that was open: a NOT-NULL column missing
// from `_create_required` passes the completeness gate and then dies at the
// INSERT, where `insertOrIgnore` swallowed it — no row, no quarantine, no log.
// transactions.posted_at/booked_at/value_date and goals.start_date/target_date
// were all in that gap.
it('MergeRulesRegistry names every NOT-NULL-without-default column of every registered table in _create_required', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $schemaBuilder = $connection->getSchemaBuilder();

    $registry = new MergeRulesRegistry;

    /** @var array<string, list<string>> $unlisted */
    $unlisted = [];

    // The reader's own row is never inserted from the wire — RowOwnership
    // refuses a create for it — so a required-column list there would describe
    // an insert that cannot happen.
    $ownership = app(RowOwnership::class);

    foreach (array_keys($registry->rules()) as $table) {
        if ($ownership->isSelfScoped($table)) {
            continue;
        }

        /** @var list<string> $notNullWithoutDefault */
        $notNullWithoutDefault = collect($schemaBuilder->getColumns($table))
            ->reject(static fn (array $col): bool => (bool) $col['auto_increment'])
            ->filter(static fn (array $col): bool => $col['nullable'] === false && $col['default'] === null)
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff(
            $notNullWithoutDefault,
            $registry->requiredCreateColumns($table),
            applierSeededColumns(),
        ));

        if ($missing !== []) {
            $unlisted[$table] = $missing;
        }
    }

    $rendered = [];
    foreach ($unlisted as $table => $cols) {
        $rendered[] = sprintf('%s => [%s]', $table, implode(', ', $cols));
    }

    expect($unlisted)->toBe(
        [],
        'These NOT-NULL-without-default columns are absent from _create_required, so a create missing one is discarded instead of quarantined: '.implode('; ', $rendered),
    );
});
