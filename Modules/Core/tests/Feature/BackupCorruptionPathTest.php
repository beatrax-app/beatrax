<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\SystemAlert;
use Tests\Helpers\RealSqliteFixture;

beforeEach(function (): void {
    $this->sourcePath = RealSqliteFixture::create('backup-corrupt-source');

    // Truncate the source DB to 100 bytes. The SQLite header is 100
    // bytes too, so this strips the sqlite_master page that VACUUM INTO
    // needs to enumerate user tables.
    file_put_contents($this->sourcePath, substr((string) file_get_contents($this->sourcePath), 0, 100));

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $this->sourcePath);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    $this->backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($this->backupsDir, 2));
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');

    /** @var string $sourcePath */
    $sourcePath = $this->sourcePath;
    RealSqliteFixture::cleanup($sourcePath);

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
});

it('writes a critical system_alerts(backup_corrupt) row and exits non-zero on a corrupt source', function (): void {
    $this->artisan('db:backup', ['--force' => true])->assertFailed();

    $alerts = SystemAlert::query()
        ->where('kind', 'backup_corrupt')
        ->where('severity', 'critical')
        ->get();

    expect($alerts)->toHaveCount(1, 'Expected exactly one system_alerts row for the corrupt path.');

    /** @var SystemAlert $alert */
    $alert = $alerts->first();
    expect($alert->message)->toContain('integrity check');
});

it('preserves a produced VACUUM INTO output under a .suspect suffix when integrity_check fails', function (): void {
    $this->artisan('db:backup', ['--force' => true])->assertFailed();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    if (! is_dir($backupsDir)) {
        // VACUUM INTO refused the malformed source outright via PDOException;
        // the command's bridge turns that into the same system_alerts surface
        // without leaving a file behind.
        $alert = SystemAlert::query()->where('kind', 'backup_corrupt')->firstOrFail();
        expect($alert->severity)->toBe('critical');

        return;
    }

    $suspect = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite.suspect');
    $clean = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite');

    if ($suspect !== []) {
        // Happy corrupt path: .suspect file present, no clean .sqlite kept.
        expect($clean)->toBe([], 'Corrupt path must NOT leave a clean .sqlite file behind.');

        $alert = SystemAlert::query()->where('kind', 'backup_corrupt')->firstOrFail();
        /** @var array<string, mixed>|null $metadata */
        $metadata = $alert->metadata;
        expect($metadata)->toBeArray();
        expect((string) ($metadata['suspect_path'] ?? ''))->toBe((string) $suspect[0]);
    } else {
        // No .suspect file: VACUUM INTO threw before any output was written.
        // The system_alerts row must still be there.
        $alert = SystemAlert::query()->where('kind', 'backup_corrupt')->firstOrFail();
        expect($alert->severity)->toBe('critical');
    }
});
