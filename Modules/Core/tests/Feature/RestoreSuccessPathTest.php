<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Tests\Helpers\RealSqliteFixture;

/*
 * Drives the happy path of `php artisan db:restore --confirm
 * --force-maintenance <source>`:
 *  - Source file is integrity-clean.
 *  - Command writes a pre-restore snapshot at chmod 0600 BEFORE the swap.
 *  - DB::purge() releases the live connection's file handle.
 *  - Source file is copied over the configured database path.
 *  - Post-swap PRAGMA integrity_check runs via the framework's connection
 *    (so the SqliteOptimizationsProvider's ConnectionEstablished
 *    listener re-applies WAL + synchronous against the swapped-in file).
 *  - --force-maintenance brought the app down at the start and the
 *    happy path brings it back up at the end.
 *  - Seeded row in the source is queryable after the swap.
 */

beforeEach(function (): void {
    // Build the live + source on-disk fixtures separately. The "live"
    // file is what the command will overwrite; the "source" is the
    // file passed on the command line.
    $this->livePath = RealSqliteFixture::create('restore-success-live');

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $this->livePath);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    $this->backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-restore-success-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($this->backupsDir, 2));

    /** @var Kernel $artisan */
    $artisan = $this->app->make(Kernel::class);
    $artisan->call('up');
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');

    /** @var string $livePath */
    $livePath = $this->livePath;
    RealSqliteFixture::cleanup($livePath);

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    if (is_dir($backupsDir)) {
        foreach ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'*') as $file) {
            if (is_file((string) $file)) {
                @unlink((string) $file);
            }
        }
        @rmdir($backupsDir);
        @rmdir(dirname($backupsDir));
        @rmdir(dirname($backupsDir, 2));
    }

    /** @var Kernel $artisan */
    $artisan = $this->app->make(Kernel::class);
    $artisan->call('up');
});

it('round-trips a valid source: pre-restore snapshot saved, swap completes, integrity ok, app back up', function (): void {
    // Build a source fixture and seed a known row so we can verify the
    // post-swap DB carries it. Use raw PDO to write the row outside any
    // Laravel connection cache.
    $sourcePath = RealSqliteFixture::create('restore-success-source');
    $pdo = new PDO('sqlite:'.$sourcePath, options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("INSERT INTO transactions (id, user_id, amount_minor, currency, booked_at) VALUES (101, 1, 4242, 'EUR', '2026-05-19')");
    unset($pdo);

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    /** @var string $livePath */
    $livePath = $this->livePath;

    try {
        $this->artisan('db:restore', [
            'path' => $sourcePath,
            '--confirm' => true,
            '--force-maintenance' => true,
        ])
            ->expectsOutputToContain('Pre-restore snapshot')
            ->expectsOutputToContain('Restore complete')
            ->assertExitCode(0);

        // (a) Pre-restore snapshot exists at chmod 0600.
        $snapshots = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'pre-restore-*.sqlite');
        expect($snapshots)->toHaveCount(1, 'Expected exactly one pre-restore-*.sqlite snapshot.');
        $snapshot = (string) $snapshots[0];
        expect(fileperms($snapshot) & 0o777)->toBe(0o600, 'Pre-restore snapshot must be chmod 0600.');

        // (b) The live DB file now mirrors the source: a fresh PDO over
        //     the live path should find the seeded row.
        $pdo = new PDO('sqlite:'.$livePath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $stmt = $pdo->query('SELECT amount_minor FROM transactions WHERE id = 101');
        $row = $stmt === false ? false : $stmt->fetchColumn();
        expect($row)->not->toBe(false, 'Post-swap DB must contain the seeded row from the source.');
        expect((int) $row)->toBe(4242);
        unset($pdo);

        // (c) The app is back up after the happy path.
        /** @var Filesystem $files */
        $files = $this->app->make(Filesystem::class);
        expect($files->exists(base_path('storage/framework/down')))->toBeFalse('App should be up after the happy path.');
    } finally {
        RealSqliteFixture::cleanup($sourcePath);
    }
});
