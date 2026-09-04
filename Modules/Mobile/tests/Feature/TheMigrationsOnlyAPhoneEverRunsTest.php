<?php

declare(strict_types=1);

/**
 * @link ../../../../.docs/features/mobile/architecture.md#the-migrations-only-a-phone-ever-runs
 */

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

// The two platforms execute different migration sets. `artisan migrate` loads
// database/schema/sqlite-schema.sql and starts after the migrations that dump
// already recorded; MobileFirstLaunchBootstrap drives Migrator->run() directly,
// the Migrator never loads a dump, and MigrateCommand's loader shells out to a
// `sqlite3` binary the phone does not carry. So the stretch the dump covers is
// code ONLY a phone ever executes — invisible to the desktop, to CI and to this
// suite, until this file.
//
// One bug in that stretch reached every new install on 2026-08-29: a foreign
// key SQLite cannot add in place, a table rebuild whose `insert into
// "__temp__transactions" ... from "transactions"` read as a user write, and a
// listener reaching a `cache` table a later migration had not created yet. The
// run died on migration eighteen of two hundred and nine and the app opened on
// thirteen tables of a hundred and two. SQLite reports
// supportsSchemaTransactions() false, so the half-applied run stays: reinstall
// is the only exit.
//
// Each schema is built by a probe in a process of its own. The phone migrates
// onto the DEFAULT connection with a DATABASE cache store, and a test that
// swaps the default connection inside a phpunit worker leaves RefreshDatabase
// holding one that no longer exists — three thousand six hundred setUp
// failures from one file that passes alone.

/**
 * @return array<string, mixed> the schema one platform's first launch produces
 */
function schemaBuiltByFirstLaunch(bool $loadSchemaDump): array
{
    /** @var array<string, array<string, mixed>> $built */
    static $built = [];

    $key = $loadSchemaDump ? 'desktop' : 'phone';
    if (array_key_exists($key, $built)) {
        return $built[$key];
    }

    $workspace = sys_get_temp_dir().'/first-launch-'.bin2hex(random_bytes(6));
    mkdir($workspace.'/storage', 0755, true);
    $database = $workspace.'/database.sqlite';
    touch($database);

    $php = (new PhpExecutableFinder)->find();
    $process = new Process(
        [$php === false ? 'php' : $php, base_path('tests/Support/first-launch-schema-probe.php')],
        base_path(),
        [
            'FIRST_LAUNCH_APP_ROOT' => base_path(),
            'FIRST_LAUNCH_LOAD_SCHEMA' => $loadSchemaDump ? '1' : '0',
            // What the device runs, not what the suite runs: `production`
            // reaches the providers a phone boots, and a file database is the
            // only kind whose table rebuilds and PRAGMAs behave as they do
            // there. `:memory:` is also the one case Laravel skips the dump for.
            'APP_ENV' => 'production',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'CACHE_STORE' => 'database',
            'SESSION_DRIVER' => 'database',
            // Keeps the completion marker and the log out of the checkout.
            'NATIVEPHP_STORAGE_PATH' => $workspace.'/storage',
        ],
        null,
        300,
    );

    $process->run();

    expect($process->getExitCode())->toBe(
        0,
        'The '.$key.' first-launch probe did not finish: '.$process->getErrorOutput(),
    );

    /** @var array<string, mixed> $report */
    $report = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

    // Only once the report is in memory, and only on the path where the probe
    // succeeded: a workspace left behind is the failing schema itself, which is
    // the one thing worth opening by hand.
    app(Filesystem::class)->deleteDirectory($workspace);

    return $built[$key] = $report;
}

/**
 * @param  array<string, mixed>  $report
 * @return string where a first-launch run stopped and what stopped it
 */
function whereFirstLaunchStopped(array $report): string
{
    /** @var array{class: string, message: string}|null $error */
    $error = $report['error'];
    /** @var list<string> $applied */
    $applied = $report['applied'];

    return $error === null
        ? 'the run finished'
        : 'stopped after '.count($applied).' migrations, the last being '
            .(end($applied) ?: 'none').' — '.$error['class'].': '.$error['message'];
}

/** @return string the tables one side has and the other does not, for a failure message */
function firstLaunchTableGap(): string
{
    $phone = schemaBuiltByFirstLaunch(false)['tables'];
    $desktop = schemaBuiltByFirstLaunch(true)['tables'];

    return 'missing from the phone: '.implode(', ', array_diff($desktop, $phone))
        .' | only on the phone: '.implode(', ', array_diff($phone, $desktop));
}

it('builds a whole schema from an empty file, the way a phone does', function (): void {
    $phone = schemaBuiltByFirstLaunch(false);

    // The error first: a count assertion on a run that threw reports the wrong
    // thing, and the message names the migration the phone died on.
    expect($phone['error'])->toBeNull(whereFirstLaunchStopped($phone));
    expect($phone['pending'])->toBeFalse('the migrator still has work left over');
    expect($phone['markerRaised'])->toBeFalse('the first-launch schema marker was raised');
    expect($phone['preloaded'])->toBe([]);
    expect(count($phone['applied']))->toBeGreaterThan(150);
});

it('runs the stretch of migrations the dump spares every desktop', function (): void {
    $desktop = schemaBuiltByFirstLaunch(true);

    expect($desktop['error'])->toBeNull(whereFirstLaunchStopped($desktop));
    expect($desktop['pending'])->toBeFalse('the migrator still has work left over');

    // Not a fixed number: the dump is re-squashed as the tree grows. What must
    // hold is that the stretch exists at all — an empty one would mean this
    // file is proving nothing the desktop suite does not already prove.
    expect(count($desktop['preloaded']))->toBeGreaterThan(0);

    // Sorted, because the two run them in different ORDERS and only one of the
    // two orders is the phone's. The dump replays its own hundred-odd first
    // whatever their names; the phone takes every file in filename order, which
    // puts a vendor migration dated before any of ours at position one.
    $applied = schemaBuiltByFirstLaunch(false)['applied'];
    sort($applied);
    $reference = $desktop['applied'];
    sort($reference);

    expect($applied)->toBe($reference);
});

it('ends on the same tables the squashed dump produces', function (): void {
    expect(schemaBuiltByFirstLaunch(false)['tables'])
        ->toBe(schemaBuiltByFirstLaunch(true)['tables'], firstLaunchTableGap());
});

it('ends on the same columns, indexes and foreign keys', function (string $facet): void {
    $phone = schemaBuiltByFirstLaunch(false)[$facet];
    $desktop = schemaBuiltByFirstLaunch(true)[$facet];

    $differing = array_keys(array_filter(
        $desktop,
        fn (array $definition, string $table): bool => ($phone[$table] ?? []) !== $definition,
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($differing)->toBe([], 'tables whose '.$facet.' differ between the two first launches');
})->with(['columns', 'indexes', 'foreignKeys']);
