<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

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
