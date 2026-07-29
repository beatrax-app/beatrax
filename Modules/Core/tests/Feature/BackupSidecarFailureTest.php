<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Contracts\Clock;
use Tests\Helpers\RealSqliteFixture;

/*
 * What db:backup does when the .meta.json sidecar cannot be written.
 *
 * A backup on disk with no sidecar is worse than no backup: the next run's
 * smart-skip reads the missing sidecar as "no recent backup exists" and
 * silently re-writes, so the failure has to surface. The command records a
 * critical system_alerts row and exits non-zero.
 *
 * It did not, for as long as the guards had been there. file_put_contents(),
 * chmod() and rename() raise E_WARNING on failure, and Laravel's error handler
 * converts that to an ErrorException before the `=== false` comparison runs —
 * so the throw never happened, and the caller's catch (RuntimeException) would
 * not have caught an ErrorException anyway. The whole path was dead and the
 * alert never fired.
 */
beforeEach(function (): void {
    $this->sourcePath = RealSqliteFixture::create('backup-sidecar-source');

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $this->sourcePath);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage';
    $this->backupsDir = $this->storageRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');

    /** @var string $sourcePath */
    $sourcePath = $this->sourcePath;
    RealSqliteFixture::cleanup($sourcePath);

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    if (is_dir($backupsDir)) {
        foreach ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'*') as $entry) {
            is_dir((string) $entry) ? @rmdir((string) $entry) : @unlink((string) $entry);
        }
        @rmdir($backupsDir);
        @rmdir(dirname($backupsDir));
        @rmdir(dirname($backupsDir, 2));
    }
});

/**
 * The destination basename the command will choose on this run. The timestamp
 * comes from the injected Clock, so the sidecar paths can be occupied before
 * the command reaches them.
 */
function sidecarBasename(mixed $app): string
{
    /** @var Clock $clock */
    $clock = $app->make(Clock::class);

    return 'beatrax-'.$clock->now()->format('Y-m-d-His').'.sqlite';
}

// file_put_contents() refuses a path that is already a directory, which is the
// one sidecar failure reachable without a filesystem that lies about writes.
it('records a critical alert and fails when the sidecar cannot be written', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    (new Filesystem)->ensureDirectoryExists($backupsDir);

    $basename = sidecarBasename($this->app);
    mkdir($backupsDir.DIRECTORY_SEPARATOR.$basename.'.meta.json.tmp');

    $this->artisan('db:backup', ['--force' => true])->assertExitCode(1);

    // The backup itself succeeded — that is the whole reason the missing
    // sidecar is dangerous rather than merely disappointing.
    expect(is_file($backupsDir.DIRECTORY_SEPARATOR.$basename))->toBeTrue()
        ->and(is_file($backupsDir.DIRECTORY_SEPARATOR.$basename.'.meta.json'))->toBeFalse();

    $alert = DB::table('system_alerts')
        ->where('kind', 'backup_corrupt')
        ->where('severity', 'critical')
        ->first();

    expect($alert)->not->toBeNull();
    expect((string) $alert->metadata)->toContain('sidecar_write');
});

// rename() onto a non-empty directory fails, which reaches the last of the
// three guards — the one where the sidecar content was written correctly and
// only the move into place failed.
it('records a critical alert when the sidecar cannot be renamed into place', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    (new Filesystem)->ensureDirectoryExists($backupsDir);

    $basename = sidecarBasename($this->app);
    $sidecar = $backupsDir.DIRECTORY_SEPARATOR.$basename.'.meta.json';
    mkdir($sidecar);
    file_put_contents($sidecar.DIRECTORY_SEPARATOR.'occupied', 'x');

    $this->artisan('db:backup', ['--force' => true])->assertExitCode(1);

    $alert = DB::table('system_alerts')
        ->where('kind', 'backup_corrupt')
        ->where('severity', 'critical')
        ->first();

    expect($alert)->not->toBeNull();

    // The temp file is cleaned up even on the failing path, so a retry does
    // not trip over its own leftovers.
    expect(is_file($sidecar.'.tmp'))->toBeFalse();

    @unlink($sidecar.DIRECTORY_SEPARATOR.'occupied');
    @rmdir($sidecar);
});
