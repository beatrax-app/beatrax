<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Tests\Helpers\LiveSqliteConnection;
use Tests\Helpers\RealSqliteFixture;

beforeEach(function (): void {
    // "live" is what the command overwrites; "source" is the file passed on the
    // command line.
    $this->livePath = RealSqliteFixture::create('restore-success-live');

    LiveSqliteConnection::pointAt($this->app, $this->livePath);

    $this->backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-restore-success-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($this->backupsDir, 2));

    /** @var Kernel $artisan */
    $artisan = $this->app->make(Kernel::class);
    $artisan->call('up');
});

afterEach(function (): void {
    LiveSqliteConnection::restore($this->app);
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
    // Raw PDO so the seeded row lands outside any Laravel connection cache.
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

        $snapshots = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'pre-restore-*.sqlite');
        expect($snapshots)->toHaveCount(1, 'Expected exactly one pre-restore-*.sqlite snapshot.');
        $snapshot = (string) $snapshots[0];
        expect(fileperms($snapshot) & 0o777)->toBe(0o600, 'Pre-restore snapshot must be chmod 0600.');

        $pdo = new PDO('sqlite:'.$livePath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $stmt = $pdo->query('SELECT amount_minor FROM transactions WHERE id = 101');
        $row = $stmt === false ? false : $stmt->fetchColumn();
        expect($row)->not->toBe(false, 'Post-swap DB must contain the seeded row from the source.');
        expect((int) $row)->toBe(4242);
        unset($pdo);

        /** @var Filesystem $files */
        $files = $this->app->make(Filesystem::class);
        expect($files->exists(base_path('storage/framework/down')))->toBeFalse('App should be up after the happy path.');
    } finally {
        RealSqliteFixture::cleanup($sourcePath);
    }
});
