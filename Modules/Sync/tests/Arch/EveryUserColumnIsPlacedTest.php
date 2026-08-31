<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

// Every other covered table is one thing: the whole row syncs or none of it
// does. `users` never will be — it holds the reader's settings beside this
// device's password and theme — so the answer has to be per column. A
// table-level check is what let `period_start_day` be added, key every
// envelope row, and never leave the device it was set on.

/** @return list<string> */
function userColumnsOnDisk(): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return array_values($db->connection()->getSchemaBuilder()->getColumnListing('users'));
}

it('places every users column in exactly one of synced, device-local, or asked of every joiner', function (): void {
    $registry = new MergeRulesRegistry;

    $synced = $registry->syncedColumns('users');
    $deviceLocal = $registry->deviceLocalColumns('users');
    $joiner = $registry->askedOfEveryJoiner('users');
    $placed = [...$synced, ...$deviceLocal, ...$joiner];

    $unplaced = array_values(array_diff(userColumnsOnDisk(), $placed));

    expect($unplaced)->toBe([], sprintf(
        "A new users column is in none of the three lists, so nobody decided whether it travels:\n  - %s\n"
        ."Add it to the registry's `users` field map, to DEVICE_LOCAL_COLUMNS, or to ASKED_OF_EVERY_JOINER.",
        implode("\n  - ", $unplaced),
    ));
});

it('names no users column that the table does not have', function (): void {
    $registry = new MergeRulesRegistry;

    $placed = [
        ...$registry->syncedColumns('users'),
        ...$registry->deviceLocalColumns('users'),
        ...$registry->askedOfEveryJoiner('users'),
    ];

    $phantom = array_values(array_diff($placed, userColumnsOnDisk()));

    expect($phantom)->toBe([], 'A list names a column `users` no longer has: '.implode(', ', $phantom));
});

it('claims no users column twice', function (): void {
    $registry = new MergeRulesRegistry;

    $placed = [
        ...$registry->syncedColumns('users'),
        ...$registry->deviceLocalColumns('users'),
        ...$registry->askedOfEveryJoiner('users'),
    ];

    $counts = array_count_values($placed);
    $twice = array_keys(array_filter($counts, static fn (int $n): bool => $n > 1));

    expect($twice)->toBe([], 'A users column is claimed by two lists: '.implode(', ', $twice));
});

it('keeps every column it does sync off the never-on-the-wire answer', function (): void {
    $registry = new MergeRulesRegistry;

    expect(array_values(array_intersect(
        $registry->syncedColumns('users'),
        $registry->columnsNeverOnTheWire('users'),
    )))->toBe([]);
});
