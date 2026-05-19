<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Tests\Helpers\RealSqliteFixture;

/*
 * Drives `php artisan db:backup` end-to-end against a real on-disk
 * SQLite fixture: the `:memory:` test connection cannot back a
 * VACUUM INTO path, so each test seeds a fresh on-disk file via
 * RealSqliteFixture and rebinds the default sqlite connection to it.
 *
 * Coverage:
 *  - --force happy path: produces diederik-YYYY-MM-DD-HHMMSS.sqlite at
 *    chmod 0600 plus a .meta.json sidecar at chmod 0600.
 *  - Smart-skip: a second run without --force prints "Skipped" and
 *    produces no new file.
 *  - Retention: pre-seeded historical files outside the 7-daily and
 *    4-Sunday keep set are pruned on success.
 */

beforeEach(function (): void {
    // Build a real on-disk source SQLite file. The fixture seeds the
    // minimal transactions + system_alerts schemas so VACUUM INTO has
    // tables to copy and the post-VACUUM integrity_check has something
    // to validate.
    $this->sourcePath = RealSqliteFixture::create('backup-test-source');

    // Rebind the `sqlite` connection at the source file so the
    // command's VACUUM INTO + PRAGMA data_version read run against
    // a real on-disk database. Keep the test-default
    // `sqlite_testing` (`:memory:`) connection in place so
    // RefreshDatabase + SystemAlert::create() continue working — the
    // command explicitly uses the named `sqlite` connection for the
    // VACUUM INTO operation, not the framework default.
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $this->sourcePath);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    // Per-test backups directory under sys_get_temp_dir() so multiple
    // tests do not collide. The command resolves the directory through
    // the `core.backups_directory` container binding; the override
    // here keeps tests deterministic without touching the real
    // storage/app/backups path on the developer machine.
    $this->backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-test-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    $this->app->instance('core.backups_directory', $this->backupsDir);
});

afterEach(function (): void {
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

it('produces a chmod-600 .sqlite + .meta.json pair when invoked with --force', function (): void {
    $this->artisan('db:backup', ['--force' => true])
        ->expectsOutputToContain('Backup written:')
        ->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    expect(is_dir($backupsDir))->toBeTrue();

    $sqliteFiles = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'diederik-*.sqlite');
    $metaFiles = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'diederik-*.sqlite.meta.json');

    expect($sqliteFiles)->toHaveCount(1, 'Expected exactly one .sqlite backup file.');
    expect($metaFiles)->toHaveCount(1, 'Expected exactly one .meta.json sidecar.');

    $sqlite = (string) $sqliteFiles[0];
    $meta = (string) $metaFiles[0];

    // Both files MUST be chmod 0600.
    expect(fileperms($sqlite) & 0o777)->toBe(0o600, 'Backup file must be mode 0600.');
    expect(fileperms($meta) & 0o777)->toBe(0o600, 'Meta sidecar must be mode 0600.');

    // Sidecar JSON must carry the four expected keys.
    $decoded = json_decode((string) file_get_contents($meta), true);
    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKeys(['data_version', 'started_at', 'completed_at', 'integrity']);
    expect($decoded['integrity'])->toBe('ok');
    expect($decoded['data_version'])->toBeInt();
});

it('skips a second invocation when data_version is unchanged and --force is absent', function (): void {
    // First run — writes a backup.
    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $afterFirst = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'diederik-*.sqlite');
    expect($afterFirst)->toHaveCount(1);

    // Second run without --force should smart-skip (no DB writes happened).
    $this->artisan('db:backup')
        ->expectsOutputToContain('Skipped')
        ->assertSuccessful();

    $afterSecond = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'diederik-*.sqlite');
    expect($afterSecond)->toHaveCount(1, 'Smart-skip path must NOT create a second .sqlite file.');
});

it('prunes pre-seeded historical backups outside the 7-daily + 4-Sunday keep set', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);

    // Seed 8 historical backups dated 2026-05-01 .. 2026-05-08. None are
    // Sundays (2026-05-03 IS a Sunday in 2026 though — verify). Actually,
    // calendar check: 2026-05-03 was a Sunday, 2026-05-10 Sunday.
    // Seeding 2026-04-25..2026-05-02 to avoid Sunday rescue noise during
    // pruning of the dailies.
    $seedDates = [
        '2026-04-22-030000', // Wed
        '2026-04-23-030000', // Thu
        '2026-04-24-030000', // Fri
        '2026-04-25-030000', // Sat
        '2026-04-27-030000', // Mon (skip Sun 26 to keep test simple)
        '2026-04-28-030000', // Tue
        '2026-04-29-030000', // Wed
        '2026-04-30-030000', // Thu
    ];

    foreach ($seedDates as $stamp) {
        $files->put($backupsDir.DIRECTORY_SEPARATOR.'diederik-'.$stamp.'.sqlite', 'seeded');
        $files->put(
            $backupsDir.DIRECTORY_SEPARATOR.'diederik-'.$stamp.'.sqlite.meta.json',
            json_encode(['data_version' => 1, 'started_at' => 'x', 'completed_at' => 'x', 'integrity' => 'ok']) ?: '',
        );
    }

    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    $remaining = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'diederik-*.sqlite');
    // 8 seeded + 1 new = 9 candidate dailies; retention keeps the 7
    // newest. The newest seeded is 2026-04-30; the freshly-written one
    // is today's. So the 7 newest are: today + 04-30, 04-29, 04-28,
    // 04-27, 04-25, 04-24. 04-22 + 04-23 should be pruned (no Sundays
    // among the seed set, so no weekly rescue).
    expect(count($remaining))->toBe(7, 'Expected exactly 7 .sqlite files after pruning.');
});
