<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\SystemAlert;
use Tests\Helpers\RealSqliteFixture;

/*
 * Drives the corrupt-source / corrupt-VACUUM-output paths of `db:backup`:
 * the command must exit non-zero, write a `system_alerts` row with
 * kind=`backup_corrupt` and severity=`critical`, and either rename a
 * produced VACUUM INTO output to `.suspect` (if the integrity check
 * tripped) or surface the failure exclusively through `system_alerts`
 * (if VACUUM INTO itself threw an SQLSTATE exception against the
 * malformed source).
 *
 * Corruption recipe: open RealSqliteFixture, then truncate the source
 * .sqlite to 100 bytes — the first sqlite_master page is gone, so
 * VACUUM INTO either fails outright or produces an output that fails
 * `PRAGMA integrity_check`. Both branches MUST converge on the same
 * user-visible failure surface (system_alerts row + non-zero exit).
 */

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
        // VACUUM INTO refused the malformed source outright via PDOException.
        // The exception bridge in the command turns that into the same
        // system_alerts surface without leaving a file behind, which is
        // an acceptable corrupt-path outcome — the alert is the load-
        // bearing user-visible signal.
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
        // Exception-bridge corrupt path: no .suspect file produced because
        // VACUUM INTO threw before any output was written. Still must have
        // recorded the system_alerts row.
        $alert = SystemAlert::query()->where('kind', 'backup_corrupt')->firstOrFail();
        expect($alert->severity)->toBe('critical');
    }
});
