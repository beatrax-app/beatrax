<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Contracts\Clock;
use Tests\Helpers\RealSqliteFixture;

it('reports installed versions and exits 0 on a healthy environment', function (): void {
    $this->artisan('diederik:doctor')
        ->expectsOutputToContain('PHP')
        ->expectsOutputToContain('Composer')
        ->expectsOutputToContain('SQLite')
        ->assertSuccessful();
});

it('prints lines for each Phase 11 probe (WAL / synchronous / backup freshness)', function (): void {
    // Build a real on-disk SQLite source + a fresh sidecar so all three
    // new probes report ok. Without these, the freshness probe would
    // warn — which is correct behaviour but noisier than this test
    // wants to observe.
    $sourcePath = RealSqliteFixture::create('doctor-cmd-source');
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $sourcePath);
    $config->set('database.default', 'sqlite');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');
    // Re-apply synchronous=NORMAL because the framework default just
    // changed and the ConnectionEstablished listener may or may not
    // have fired against the fresh fixture during the swap.
    $db->connection('sqlite')->statement('PRAGMA synchronous = NORMAL');

    $backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-doctor-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    $this->app->instance('core.backups_directory', $backupsDir);

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
            ->expectsOutputToContain('Backup freshness')
            ->assertSuccessful();
    } finally {
        RealSqliteFixture::cleanup($sourcePath);
        foreach ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'*') as $entry) {
            if (is_file((string) $entry)) {
                @unlink((string) $entry);
            }
        }
        @rmdir($backupsDir);
        @rmdir(dirname($backupsDir));
        @rmdir(dirname($backupsDir, 2));
    }
});

it('exits non-zero with a warning marker after a forced PRAGMA journal_mode drift', function (): void {
    $sourcePath = RealSqliteFixture::create('doctor-cmd-drift');
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $sourcePath);
    $config->set('database.default', 'sqlite');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');
    // Force journal_mode off WAL on the on-disk file. The WAL probe
    // should observe the drift and the command should exit 1.
    $db->connection('sqlite')->statement('PRAGMA journal_mode = DELETE');

    $backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-doctor-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    $this->app->instance('core.backups_directory', $backupsDir);

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    $tenMinutesAgo = $clock->now()->subMinutes(10);
    // Keep the freshness probe ok so only WAL trips.
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
        $exitCode = $this->artisan('diederik:doctor')->run();
        expect($exitCode)->toBeGreaterThanOrEqual(1);
    } finally {
        RealSqliteFixture::cleanup($sourcePath);
        foreach ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'*') as $entry) {
            if (is_file((string) $entry)) {
                @unlink((string) $entry);
            }
        }
        @rmdir($backupsDir);
        @rmdir(dirname($backupsDir));
        @rmdir(dirname($backupsDir, 2));
    }
});
