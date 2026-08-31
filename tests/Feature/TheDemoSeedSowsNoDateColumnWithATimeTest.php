<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// demo:seed is the only writer that filled forecast_shortfall_windows.starts_at
// with a Carbon rather than a day string, and it left one nineteen-character row
// among eleven bare ones. A column holding both shapes is the failure: the
// horizon comparison keeps the short rows and drops the long one.
it('leaves every DATE column in the demo data ten characters wide', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();
    $schema = $connection->getSchemaBuilder();

    $sown = [];
    $wrong = [];

    foreach ($schema->getTables() as $table) {
        $name = is_array($table) && isset($table['name']) && is_string($table['name']) ? $table['name'] : null;
        if ($name === null || str_starts_with($name, 'sqlite_')) {
            continue;
        }

        foreach ($schema->getColumns($name) as $column) {
            if (($column['type_name'] ?? null) !== 'date' || ! is_string($column['name'])) {
                continue;
            }

            $widths = $connection->table($name)
                ->whereNotNull($column['name'])
                ->distinct()
                ->pluck($connection->raw('length("'.$column['name'].'")'))
                ->map(static fn (mixed $width): int => is_numeric($width) ? (int) $width : 0)
                ->all();

            if ($widths === []) {
                continue;
            }

            $sown[] = $name.'.'.$column['name'];

            if ($widths !== [10]) {
                sort($widths);
                $wrong[] = $name.'.'.$column['name'].' => widths '.implode(',', $widths);
            }
        }
    }

    expect($sown)->not->toBeEmpty();
    expect($wrong)->toBe([], 'A DATE column the demo sowed in more than one shape:');
});
