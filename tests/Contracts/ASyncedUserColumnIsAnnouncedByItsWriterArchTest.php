<?php

declare(strict_types=1);

use Modules\Sync\Internal\Config\MergeRulesRegistry;

// The users row is one reader's settings mixed with one device's password, so
// a writer that saves the model announces nothing by itself. Three synced
// columns were written that way: community_settings never left the device the
// toggle was flipped on, and envelope_activated_at — the carryover fold's
// genesis anchor — reached a peer only if that peer paired after activation.

// WriteUserPreference is the announcement and MergeRulesRegistry is the list it
// is checked against; neither writes a reader's settings itself. Whole repo
// paths rather than filename suffixes: `str_ends_with($path, 'X.php')` excuses
// any file in any module whose path happens to end that way.
const SYNCED_USER_COLUMN_SEAMS = [
    'Modules/Core/Public/Actions/WriteUserPreference.php',
    'Modules/Sync/Internal/Config/MergeRulesRegistry.php',
];

// A file that reaches WriteUserPreference, or dispatches EntityMutated, or
// calls announce() at all, is taken as covering the write beside it — which is
// a claim about the FILE, and the next write added to that file inherits it for
// free. So the sites it currently waves through are pinned, compared in both
// directions: a new unannounced write into one of these files fails here, and a
// site that stops needing the exemption fails too.
const ANNOUNCED_USER_COLUMN_WRITES = [
    'Modules/Budgets/Public/Services/EnvelopeActivationService.php :: envelope_activated_at',
    'Modules/Community/Internal/Http/Livewire/SharedListSettingsPanel.php :: community_settings',
    'Modules/Receipts/Public/Actions/ApplyReceiptConflictResolution.php :: receipt_conflict_resolution',
    'Modules/Shell/Internal/Http/Livewire/SettingsPage.php :: base_currency',
    'Modules/Shell/Internal/Http/Livewire/SettingsPage.php :: default_currency_view',
    'Modules/Shell/Internal/Http/Livewire/SettingsPage.php :: drift_alert_threshold_percent',
    'Modules/Shell/Internal/Http/Livewire/SettingsPage.php :: period_start_day',
    'Modules/Shell/Internal/Http/Livewire/SettingsPage.php :: recurring_detection_window_months',
    'Modules/Shell/Internal/Http/Livewire/SettingsPage.php :: recurring_income_min_amount_minor',
];

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
 * Every production file that could persist a users column. Tests assert about
 * these writes, and a migration, a seeder or a factory builds the row the
 * production path would have announced — none of them is a mutation a peer
 * should hear about.
 *
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

/**
 * Every users-column write outside the two seams, split by whether the file it
 * sits in announces anything at all.
 *
 * @return array{unannounced: list<string>, excused: list<string>, files: int}
 */
function userColumnWriteSites(): array
{
    $columns = syncedUserColumns();
    $unannounced = [];
    $excused = [];
    $files = 0;

    foreach (userColumnWriterFiles() as $file) {
        $relative = str_replace(base_path().'/', '', $file);

        if (in_array($relative, SYNCED_USER_COLUMN_SEAMS, true)) {
            continue;
        }

        $files++;
        $source = (string) file_get_contents($file);
        $announces = preg_match('/->announce\(|EntityMutated\(|WriteUserPreference/', $source) === 1;

        foreach ($columns as $column) {
            if (! userColumnOffenders($column, $source)) {
                continue;
            }

            $site = $relative.' :: '.$column;
            $announces ? $excused[] = $site : $unannounced[] = $site;
        }
    }

    sort($unannounced);
    sort($excused);

    return ['unannounced' => $unannounced, 'excused' => $excused, 'files' => $files];
}

it('announces every synced users column its writers persist', function (): void {
    $columns = syncedUserColumns();

    expect(count($columns))->toBeGreaterThan(
        5,
        'The merge registry named almost no synced users column, so the verdict below is about a rule that checked nothing.',
    );

    $sites = userColumnWriteSites();

    expect($sites['files'])->toBeGreaterThan(
        2_000,
        'The walk read almost none of Modules/, so the empty offender list below is a tree nobody looked at.',
    );

    expect($sites['unannounced'])->toBe([], implode("\n", [
        'These writers persist a synced users column without announcing it, so it',
        'stays on the device it was written on:',
        ...$sites['unannounced'],
        '',
        'Call WriteUserPreference::announce($userId, [$column]) after the write.',
    ]));
});

// The bypass above is the widest thing in this file, so what it covers is the
// half held to a list rather than the half left to a substring.
it('waves through only the writes already pinned as announced', function (): void {
    $excused = userColumnWriteSites()['excused'];

    $added = array_values(array_diff($excused, ANNOUNCED_USER_COLUMN_WRITES));
    $gone = array_values(array_diff(ANNOUNCED_USER_COLUMN_WRITES, $excused));

    expect($excused)->toBe(ANNOUNCED_USER_COLUMN_WRITES, implode("\n  ", [
        'A file that announces one column is treated as announcing every column it writes, which is a',
        'claim about the file rather than about the write. The sites that claim buys are pinned, so a new',
        'one is a decision somebody makes rather than a line that inherits an exemption.',
        '',
        'NEW, not pinned — check the write really is announced, then add the line:',
        ...($added === [] ? ['-'] : $added),
        '',
        'PINNED but no longer reached — the write moved or the file stopped announcing; delete the line:',
        ...($gone === [] ? ['-'] : $gone),
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
