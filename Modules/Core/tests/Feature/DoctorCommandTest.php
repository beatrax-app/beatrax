<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Console\Support\BackupSidecar;
use Modules\Core\Public\Contracts\Clock;

it('reports installed versions and probe rows on a healthy environment', function (): void {
    // A fresh sidecar so BackupFreshnessProbe reports ok; with all three probes
    // ok the inline tool checks decide the exit code.
    $backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-doctor-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($backupsDir, 2));

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    $tenMinutesAgo = $clock->now()->subMinutes(10);
    $backup = $backupsDir.DIRECTORY_SEPARATOR.'beatrax-'.$tenMinutesAgo->format('Y-m-d-His').'.sqlite';
    // Both halves: a sidecar with no copy beside it is read as no backup at
    // all, which is the state the test below this one arranges on purpose.
    $files->put($backup, 'the copy this sidecar names');
    $files->put(
        $backup.BackupSidecar::SUFFIX,
        (string) json_encode([
            'data_version' => 1,
            'started_at' => $tenMinutesAgo->subSecond()->toIso8601String(),
            'completed_at' => $tenMinutesAgo->toIso8601String(),
            'integrity' => 'ok',
        ]),
    );

    try {
        $this->artisan('beatrax:doctor')
            ->expectsOutputToContain('PHP')
            ->expectsOutputToContain('Composer')
            ->expectsOutputToContain('SQLite');
    } finally {
        foreach ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'*') as $entry) {
            if (is_file((string) $entry)) {
                @unlink((string) $entry);
            }
        }
        @rmdir($backupsDir);
        @rmdir(dirname($backupsDir));
        @rmdir(dirname($backupsDir, 2));
        putenv('NATIVEPHP_STORAGE_PATH');
    }
});

it('prints lines for each probe (WAL / synchronous / backup freshness)', function (): void {
    // The probes report against the default connection, which in the harness is
    // sqlite_testing :memory: where journal_mode is `memory` — meaningless for
    // the WAL/sync probes, but the labels still print.
    $backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-doctor-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($backupsDir, 2));

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    $tenMinutesAgo = $clock->now()->subMinutes(10);
    $backup = $backupsDir.DIRECTORY_SEPARATOR.'beatrax-'.$tenMinutesAgo->format('Y-m-d-His').'.sqlite';
    // Both halves: a sidecar with no copy beside it is read as no backup at
    // all, which is the state the test below this one arranges on purpose.
    $files->put($backup, 'the copy this sidecar names');
    $files->put(
        $backup.BackupSidecar::SUFFIX,
        (string) json_encode([
            'data_version' => 1,
            'started_at' => $tenMinutesAgo->subSecond()->toIso8601String(),
            'completed_at' => $tenMinutesAgo->toIso8601String(),
            'integrity' => 'ok',
        ]),
    );

    try {
        $this->artisan('beatrax:doctor')
            ->expectsOutputToContain('SQLite WAL mode')
            ->expectsOutputToContain('SQLite synchronous mode')
            ->expectsOutputToContain('Backup freshness');
    } finally {
        foreach ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'*') as $entry) {
            if (is_file((string) $entry)) {
                @unlink((string) $entry);
            }
        }
        @rmdir($backupsDir);
        @rmdir(dirname($backupsDir));
        @rmdir(dirname($backupsDir, 2));
        putenv('NATIVEPHP_STORAGE_PATH');
    }
});

it('exits non-zero when the BackupFreshnessProbe warns (DoctorCommand probe aggregation)', function (): void {
    // An empty backups directory makes BackupFreshnessProbe warn, which bumps the
    // aggregated exit code to >= 1. The probe drift mechanics themselves are
    // covered by DoctorProbesTest; this asserts only the command-level roll-up.
    $backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-doctor-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($backupsDir, 2));

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);

    try {
        $exitCode = $this->artisan('beatrax:doctor')->run();
        expect($exitCode)->toBeGreaterThanOrEqual(1);
    } finally {
        foreach ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'*') as $entry) {
            if (is_file((string) $entry)) {
                @unlink((string) $entry);
            }
        }
        @rmdir($backupsDir);
        @rmdir(dirname($backupsDir));
        @rmdir(dirname($backupsDir, 2));
        putenv('NATIVEPHP_STORAGE_PATH');
    }
});

// The row this command exists to carry for the merge: a create the durable op
// log holds whose row is in no table is reported nowhere else on a console.
it('prints the row counting what the op log holds and the tables do not', function (): void {
    $this->artisan('beatrax:doctor')
        ->expectsOutputToContain('rows the log still holds');
});

it('names the database whose state its rows describe', function (): void {
    // This checkout holds two: artisan opens database.sqlite while the desktop
    // runs on nativephp.sqlite, so a WAL or quarantine row is ambiguous until
    // the file is named. Read back from config rather than hardcoded, because
    // a fixed literal would satisfy the assertion without reading anything.
    $default = (string) config('database.default');
    $database = (string) config('database.connections.'.$default.'.database');

    expect($database)->not->toBe('', 'The test environment has no database path to print.');

    // One expectation, not two: expectsOutputToContain consumes a line per
    // call, so a second one can never match the line the first just took.
    $this->artisan('beatrax:doctor')
        ->expectsOutputToContain($default.': '.$database);
});
