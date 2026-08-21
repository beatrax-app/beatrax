<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Tests\Helpers\RealSqliteFixture;

// Without --force the command decides whether to back up by reading the newest
// sidecar's data_version. Every way that read can go wrong has to mean "do not
// skip": an unnecessary backup costs disk, whereas skipping on a sidecar it
// failed to parse means believing a backup exists when none does.
beforeEach(function (): void {
    $this->sourcePath = RealSqliteFixture::create('backup-skip-source');

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $this->sourcePath);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage';
    $this->backupsDir = $this->storageRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
    (new Filesystem)->ensureDirectoryExists($this->backupsDir);
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
 * @return list<string>
 */
function skipProducedBackups(string $dir): array
{
    return array_values(array_map(strval(...), (array) glob($dir.DIRECTORY_SEPARATOR.'*.sqlite')));
}

it('does not skip when there is nothing to compare against', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;

    $this->artisan('db:backup')->assertExitCode(0);

    expect(skipProducedBackups($backupsDir))->toHaveCount(1);
});

// A directory where a sidecar should be is not a hypothetical: the sidecar is
// written to a .tmp sibling and renamed, so an interrupted run can leave odd
// entries behind that still match the glob.
it('does not skip when the newest sidecar is not a file', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    mkdir($backupsDir.DIRECTORY_SEPARATOR.'beatrax-2026-01-01-000000.sqlite.meta.json');

    $this->artisan('db:backup')->assertExitCode(0);

    expect(skipProducedBackups($backupsDir))->toHaveCount(1);

    @rmdir($backupsDir.DIRECTORY_SEPARATOR.'beatrax-2026-01-01-000000.sqlite.meta.json');
});

it('does not skip when the newest sidecar does not hold an object', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    file_put_contents(
        $backupsDir.DIRECTORY_SEPARATOR.'beatrax-2026-01-01-000000.sqlite.meta.json',
        '"a bare string, not the object this expects"',
    );

    $this->artisan('db:backup')->assertExitCode(0);

    expect(skipProducedBackups($backupsDir))->toHaveCount(1);
});
