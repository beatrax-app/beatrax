<?php

declare(strict_types=1);

use Modules\Sync\Internal\Config\MergeRulesRegistry;

// The users row is one reader's settings mixed with one device's password, so
// a writer that saves the model announces nothing by itself. Three synced
// columns were written that way: community_settings never left the device the
// toggle was flipped on, and envelope_activated_at — the carryover fold's
// genesis anchor — reached a peer only if that peer paired after activation.
/**
 * @return list<string>
 */
function syncedUserColumns(): array
{
    $registry = app(MergeRulesRegistry::class);
    $offTheWire = $registry->columnsNeverOnTheWire('users');

    $columns = [];
    foreach (array_keys($registry->rules()['users'] ?? []) as $column) {
        if (str_starts_with((string) $column, '_') || in_array($column, $offTheWire, true)) {
            continue;
        }
        $columns[] = (string) $column;
    }

    return $columns;
}

/**
 * @return list<string>
 */
function userColumnWriterFiles(): array
{
    $files = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'), FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $entry) {
        $path = (string) $entry;
        if (! str_ends_with($path, '.php')) {
            continue;
        }
        foreach (['/tests/', '/Migrations/', '/Seeders/', '/Factories/'] as $excluded) {
            if (str_contains($path, $excluded)) {
                continue 2;
            }
        }
        $files[] = $path;
    }
    sort($files);

    return $files;
}

// A quote is written as `.` here rather than a character class, so the pattern
// survives being a single-quoted PHP string.
function userColumnOffenders(string $column, string $source): bool
{
    // Two write shapes, each needing its own proof that `users` is the table.
    // A bare column name is not enough: exchange_rates carries its own
    // base_currency, and matching that reported an FX job.
    $viaBuilder = preg_match('/table\(\s*.users.\s*\)/', $source) === 1
        && preg_match('/->update\([^;]{0,400}.'.$column.'.\s*=>/s', $source) === 1;

    $viaModel = preg_match('/->'.$column.'\s*=[^=]/', $source) === 1
        && preg_match('/->save\(\)/', $source) === 1;

    return $viaBuilder || $viaModel;
}

it('announces every synced users column its writers persist', function (): void {
    $columns = syncedUserColumns();
    expect($columns)->not->toBeEmpty();

    // WriteUserPreference is the announcement, and the registry is the list it
    // is checked against; neither writes a reader's settings itself.
    $seams = ['WriteUserPreference.php', 'MergeRulesRegistry.php'];

    $offenders = [];
    foreach (userColumnWriterFiles() as $file) {
        foreach ($seams as $seam) {
            if (str_ends_with($file, $seam)) {
                continue 2;
            }
        }

        $source = (string) file_get_contents($file);

        // WriteUserPreference::write() announces what it wrote, so a caller
        // reaching for that seam at all is already covered.
        if (preg_match('/->announce\(|EntityMutated\(|WriteUserPreference/', $source) === 1) {
            continue;
        }

        foreach ($columns as $column) {
            if (userColumnOffenders($column, $source)) {
                $offenders[] = str_replace(base_path().'/', '', $file).' writes '.$column.' and announces nothing';
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n", [
        'These writers persist a synced users column without announcing it, so it',
        'stays on the device it was written on:',
        ...$offenders,
        '',
        'Call WriteUserPreference::announce($userId, [$column]) after the write.',
    ]));
});

it('reports a save that leaves a synced users column unannounced', function (): void {
    // Assembled at runtime so the guard scanning its own tree cannot read this
    // fixture as a real offender.
    $save = '->save'.'()';
    $planted = '<?php $user->community_settings = $settings; $user'.$save.';';

    expect(userColumnOffenders('community_settings', $planted))->toBeTrue();
    expect(userColumnOffenders('base_currency', $planted))->toBeFalse();

    // The FX job's shape: the column name is real, the table is not users.
    $fx = '<?php $rows[] = [\'base_currency\' => $base]; $model'.$save.';';
    expect(userColumnOffenders('base_currency', $fx))->toBeFalse();
});
