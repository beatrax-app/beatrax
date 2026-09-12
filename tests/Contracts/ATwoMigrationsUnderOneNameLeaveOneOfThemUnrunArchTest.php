<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

// Laravel's migrator keys every path it collects by the file's basename, so two
// files under one name are one entry — the second replacing the first, the
// `migrations` table recording that name as run, and the loser never running on
// any device with no error raised anywhere.

// Thirty-five modules each own a migrations directory and a date prefix is only
// a day, so two of them naming one change on one day is not remote. It happened
// between two sweeps that were the same shape and were therefore described in
// the same words.

/**
 * @return list<string> every migration file the migrator is handed, absolute
 */
function migrationFilesTheMigratorCollects(): array
{
    return array_merge(
        glob(UserDataPathService::modulesPath().'/*/Database/Migrations/*.php') ?: [],
        glob(UserDataPathService::migrationsPath().'/*.php') ?: [],
    );
}

it('collects the migrations the application actually ships', function (): void {
    expect(count(migrationFilesTheMigratorCollects()))->toBeGreaterThan(
        100,
        'The walk found too few migration files to have read the tree, so its verdict is about nothing.',
    );
});

it('gives no two migrations the same name', function (): void {
    $byName = [];

    foreach (migrationFilesTheMigratorCollects() as $path) {
        $byName[basename($path, '.php')][] = str_replace(base_path().'/', '', $path);
    }

    $collisions = [];
    foreach ($byName as $name => $paths) {
        if (count($paths) > 1) {
            $collisions[] = $name.' — '.implode(' and ', $paths);
        }
    }

    expect($collisions)->toBe([], implode("\n", [
        'These migrations share one name, so the migrator runs one of them and',
        'records the name as done for both:',
        ...$collisions,
        '',
        'Rename one. The date prefix is free to move too — two changes made on',
        'one day do not have to share a timestamp.',
    ]));
});
