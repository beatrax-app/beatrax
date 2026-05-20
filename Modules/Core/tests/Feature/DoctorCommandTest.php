<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Contracts\Clock;

it('reports installed versions and probe rows on a healthy environment', function (): void {
    // Seed a fresh sidecar so the BackupFreshnessProbe reports ok; the
    // WAL / synchronous probes already report ok against the testing
    // connection per the SqliteOptimizationsProvider. With all three
    // probes ok the existing inline tool checks decide the exit code.
    $backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-doctor-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($backupsDir, 2));

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    $tenMinutesAgo = $clock->now()->subMinutes(10);
    $files->put(
        $backupsDir.DIRECTORY_SEPARATOR.'diederik-'.$tenMinutesAgo->format('Y-m-d-His').'.sqlite.meta.json',
        (string) json_encode([
            'data_version' => 1,
            'started_at' => $tenMinutesAgo->subSecond()->toIso8601String(),
            'completed_at' => $tenMinutesAgo->toIso8601String(),
            'integrity' => 'ok',
        ]),
    );

    try {
        $this->artisan('diederik:doctor')
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

it('prints lines for each Phase 11 probe (WAL / synchronous / backup freshness)', function (): void {
    // The three new probes report against the default connection. In
    // the test harness that's sqlite_testing :memory:, where journal_mode
    // is `memory` rather than `wal` — meaningless for the WAL/sync probes
    // but still useful for proving the labels are printed. The freshness
    // probe is the one we drive with a real sidecar so the row reads ok.
    $backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-doctor-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($backupsDir, 2));

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    $tenMinutesAgo = $clock->now()->subMinutes(10);
    $files->put(
        $backupsDir.DIRECTORY_SEPARATOR.'diederik-'.$tenMinutesAgo->format('Y-m-d-His').'.sqlite.meta.json',
        (string) json_encode([
            'data_version' => 1,
            'started_at' => $tenMinutesAgo->subSecond()->toIso8601String(),
            'completed_at' => $tenMinutesAgo->toIso8601String(),
            'integrity' => 'ok',
        ]),
    );

    try {
        $this->artisan('diederik:doctor')
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
    // Point the backups directory at a brand-new empty path. With no
    // sidecars present, the BackupFreshnessProbe returns warning + the
    // DoctorCommand aggregation bumps the exit code to >= 1. The
    // WAL / synchronous probes report ok against the testing connection
    // (SqliteOptimizationsProvider keeps them in sync).
    //
    // The drift-detection mechanic for WalModeProbe / SynchronousModeProbe
    // is covered exhaustively by DoctorProbesTest in isolation; this
    // feature test only verifies the command-level aggregation logic.
    $backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-doctor-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($backupsDir, 2));

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);

    try {
        $exitCode = $this->artisan('diederik:doctor')->run();
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
