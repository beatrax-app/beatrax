<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

// A phantom name in `_create_required` makes OpLogReplayer quarantine every one
// of that table's CreateRow ops, and a phantom strategy key is dead config that
// will misroute a future set. The registry must match the real schema, so a
// failure here is fixed in the registry and never by weakening the test.

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
