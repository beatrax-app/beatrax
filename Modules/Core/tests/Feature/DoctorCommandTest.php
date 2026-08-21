<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
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
    $files->put(
        $backupsDir.DIRECTORY_SEPARATOR.'beatrax-'.$tenMinutesAgo->format('Y-m-d-His').'.sqlite.meta.json',
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
    $files->put(
        $backupsDir.DIRECTORY_SEPARATOR.'beatrax-'.$tenMinutesAgo->format('Y-m-d-His').'.sqlite.meta.json',
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
