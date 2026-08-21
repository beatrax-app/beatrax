<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

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

    // The application already supplies a default for an envelope with no
    // settings row, so `overspend_mode` must not also carry a DB-level one: it
    // would drop out of the set above and stop being required on every create.
    expect($required)->toEqualCanonicalizing([
        'user_id',
        'category_id',
        'overspend_mode',
    ]);
});
