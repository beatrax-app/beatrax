<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\Sync\Public\Services\DependentRowCascade;

it('classifies every foreign key as owning or not owning', function (): void {
    $owned = array_merge(...array_values(DependentRowCascade::ownedBy()));
    $classified = array_merge($owned, DependentRowCascade::notOwned());

    $unclassified = [];
    foreach (Schema::getTableListing() as $table) {
        $name = str_contains($table, '.') ? substr($table, (int) strrpos($table, '.') + 1) : $table;

        foreach (Schema::getForeignKeys($name) as $foreignKey) {
            $column = $foreignKey['columns'][0] ?? '';

            // user_id stays discovered from the schema by UserScopedDataPurge
            // rather than repeated here, so it is nobody's registry entry.
            if ($column === 'user_id' && $foreignKey['foreign_table'] === 'users') {
                continue;
            }

            if (! in_array($name.'.'.$column, $classified, true)) {
                $unclassified[] = $name.'.'.$column.' -> '.$foreignKey['foreign_table'];
            }
        }
    }

    expect($unclassified)->toBe([]);
});

it('leaves the deleting of owned rows to the application, not the database', function (): void {
    $stillCascading = [];
    foreach (Schema::getTableListing() as $table) {
        $name = str_contains($table, '.') ? substr($table, (int) strrpos($table, '.') + 1) : $table;

        foreach (Schema::getForeignKeys($name) as $foreignKey) {
            if (($foreignKey['on_delete'] ?? '') === 'cascade') {
                $stillCascading[] = $name.'.'.($foreignKey['columns'][0] ?? '');
            }
        }
    }

    expect($stillCascading)->toBe([]);
});
