<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Backup\BackupCouldNotBeBroughtUpToDateException;
use Modules\Core\Internal\Backup\BackupFromANewerBuildException;
use Modules\Core\Public\Enums\RestoreRefusal;
use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Services\RestoreEncryptedBackup;
use Modules\Core\Public\Services\UserDataPathService;
use Tests\Helpers\CheapKdfCost;
use Tests\Helpers\LiveSqliteConnection;
use Tests\Helpers\RealSqliteFixture;

/**
 * @link ../../../../.docs/features/core/a-backup-from-another-build.md
 */

// A backup carries a database, and a database has a shape the code around it
// expects. Restoring one whose shape does not match produced no error and no
// refusal: the swap succeeded, integrity_check passed, the command printed
// success, and the application then ran against a schema its own code did not
// match. Nothing looked, in either direction — and a pending-migration check
// cannot see the newer one, because it asks whether each migration the build
// HAS was recorded as run, which a newer database answers yes to for all of
// them.
//
// The two directions answer differently. An older backup is migrated forward
// on a copy and then restored. A newer one is refused: migrations only move
// forward, so no run leads from that database to a shape this build reads.

beforeEach(function (): void {
    $this->livePath = RealSqliteFixture::create('generation-live');
    LiveSqliteConnection::pointAt($this->app, $this->livePath);

    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-generation-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage';
    $this->backupsDir = $this->storageRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    $this->stagingDir = $this->storageRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'tmp-restore';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $this->downMarker = (new UserDataPathService)->framework('down');
    $files->ensureDirectoryExists(dirname($this->downMarker));
    $files->put($this->downMarker, '');

    $this->sources = [];
    $this->shipped = [];
});

afterEach(function (): void {
    LiveSqliteConnection::restore($this->app);

    /** @var string $livePath */
    $livePath = $this->livePath;
    RealSqliteFixture::cleanup($livePath);

    /** @var list<string> $sources */
    $sources = $this->sources;
    foreach ($sources as $source) {
        RealSqliteFixture::cleanup($source);
    }

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);

    /** @var list<string> $shipped */
    $shipped = $this->shipped;
    foreach ($shipped as $directory) {
        $files->deleteDirectory($directory);
    }

    /** @var string $storageRoot */
    $storageRoot = $this->storageRoot;
    $files->deleteDirectory($storageRoot);
    @rmdir(dirname($storageRoot));

    putenv('NATIVEPHP_STORAGE_PATH');
});

// The names this build actually ships, read the same way the runner reads them
// rather than listed here — a fixture naming migrations by hand would pass
// while the comparison it is meant to prove looked somewhere else entirely.
/** @return list<string> */
function generationNamesThisBuildHas(): array
{
    /** @var Migrator $migrator */
    $migrator = app(Migrator::class);

    /** @var list<string> $names */
    $names = array_keys($migrator->getMigrationFiles(
        [...$migrator->paths(), UserDataPathService::migrationsPath()],
    ));

    return $names;
}

// A backup that records migrations and holds nothing else. Enough to be judged
// — the comparison reads the `migrations` table and nothing else — and the
// forward run below adds to it from a file this build ships.
/** @param list<string> $migrations */
function generationSourceRecording(array $migrations): string
{
    $schemas = [
        'CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL, batch INTEGER NOT NULL)',
    ];

    foreach ($migrations as $name) {
        $schemas[] = "INSERT INTO migrations (migration, batch) VALUES ('".str_replace("'", "''", $name)."', 1)";
    }

    return RealSqliteFixture::create('generation-source', $schemas);
}

// Adds one migration to the set this build ships, dated past every real one so
// it runs last. The alternative — rolling a real database back a migration —
// tests whichever migration happens to be newest that week, and tests its
// down() as much as the forward run.
/**
 * @return string the directory it was written into
 */
function generationBuildAlsoShips(string $name, string $up): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-forward-'.bin2hex(random_bytes(8));
    mkdir($directory, 0o755, true);

    file_put_contents($directory.DIRECTORY_SEPARATOR.$name.'.php', <<<MIGRATION
        <?php

        return new class extends Illuminate\\Database\\Migrations\\Migration
        {
            public function up(): void
            {
                {$up}
            }
        };
        MIGRATION);

    app(Migrator::class)->path($directory);

    return $directory;
}

/** @return list<string> */
function generationTablesIn(string $path): array
{
    /** @var list<string> $tables */
    $tables = (new PDO('sqlite:'.$path))
        ->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);

    return $tables;
}

// The whole comparison rests on this: `migrations` names every migration the
// database ran, and the build answers with the files it ships. Squashing with
// `schema:dump --prune` deletes the files the dump covers, and every one of
// them would then read as a migration this build has never heard of — turning
// every backup ever taken into one from a newer build.
it('ships a file for every migration the squashed dump records', function (): void {
    $dump = (string) file_get_contents(base_path('database/schema/sqlite-schema.sql'));

    preg_match_all("/INSERT INTO migrations VALUES\(\d+,'([^']+)'/", $dump, $matches);
    $covered = $matches[1];

    expect(count($covered))->toBeGreaterThan(0, 'the dump records no migrations, so this proves nothing');

    expect(array_values(array_diff($covered, generationNamesThisBuildHas())))
        ->toBe([], 'migrations the dump records that this build no longer ships a file for');
});

it('refuses a backup naming a schema change this build does not have', function (): void {
    $source = generationSourceRecording([
        ...generationNamesThisBuildHas(),
        '2099_01_01_000000_a_table_this_build_has_never_heard_of',
    ]);
    $this->sources = [...$this->sources, $source];

    /** @var string $livePath */
    $livePath = $this->livePath;
    $before = (string) hash_file('sha256', $livePath);

    $this->artisan('db:restore', ['path' => $source, '--confirm' => true])
        ->expectsOutputToContain('newer version of Beatrax')
        ->assertExitCode(1);

    expect(hash_file('sha256', $livePath))->toBe($before)
        ->and((array) glob($this->backupsDir.DIRECTORY_SEPARATOR.'pre-restore-*.sqlite'))->toBe([]);
});

it('brings a backup from an older build forward and restores what the run produced', function (): void {
    $carried = generationNamesThisBuildHas();
    $this->shipped = [...$this->shipped, generationBuildAlsoShips(
        '2099_01_01_000000_a_table_the_forward_run_adds',
        "Illuminate\\Support\\Facades\\Schema::create('a_table_the_forward_run_adds', function (\$table): void { \$table->id(); });",
    )];

    $source = generationSourceRecording($carried);
    $this->sources = [...$this->sources, $source];
    $untouched = (string) hash_file('sha256', $source);

    $this->artisan('db:restore', ['path' => $source, '--confirm' => true])
        ->expectsOutputToContain('Brought the backup forward to this build. Migrations run: 1')
        ->assertSuccessful();

    /** @var string $livePath */
    $livePath = $this->livePath;

    expect(generationTablesIn($livePath))->toContain('a_table_the_forward_run_adds');

    $recorded = (new PDO('sqlite:'.$livePath))->query('SELECT count(*) FROM migrations')->fetchColumn();
    expect((int) $recorded)->toBe(count($carried) + 1);

    // The operator's file is the one they still have if this goes wrong, and
    // the forward run writes a schema into whatever it is handed.
    expect(hash_file('sha256', $source))->toBe($untouched);

    // A restore that migrates is still a restore, so it takes its snapshot
    // like any other: the reader's undo is the database that was here before.
    expect((array) glob($this->backupsDir.DIRECTORY_SEPARATOR.'pre-restore-*.sqlite'))->toHaveCount(1);
});

// SQLite runs no schema transaction — supportsSchemaTransactions() is false —
// so a migration that fails part way leaves what it applied behind. The only
// undo is throwing away the file it ran against, which is why it never runs
// against the live database or the operator's backup.
it('refuses when the forward run fails, leaving the live database and the backup as they were', function (): void {
    $carried = generationNamesThisBuildHas();
    $this->shipped = [...$this->shipped, generationBuildAlsoShips(
        '2099_01_01_000000_a_migration_that_fails_part_way',
        "Illuminate\\Support\\Facades\\Schema::create('a_table_the_failed_run_added', function (\$table): void { \$table->id(); });\n"
        ."                throw new RuntimeException('the half of it that does not apply');",
    )];

    $source = generationSourceRecording($carried);
    $this->sources = [...$this->sources, $source];
    $untouched = (string) hash_file('sha256', $source);

    /** @var string $livePath */
    $livePath = $this->livePath;
    $before = (string) hash_file('sha256', $livePath);

    $this->artisan('db:restore', ['path' => $source, '--confirm' => true])
        ->expectsOutputToContain('could not update this backup')
        ->assertExitCode(1);

    expect(hash_file('sha256', $livePath))->toBe($before)
        ->and(generationTablesIn($livePath))->not->toContain('a_table_the_failed_run_added')
        ->and(hash_file('sha256', $source))->toBe($untouched)
        ->and(generationTablesIn($source))->not->toContain('a_table_the_failed_run_added');

    // Raised before the snapshot, so a refused restore leaves no trace at all
    // — and the half-built copy it ran against is not left in the clear.
    expect((array) glob($this->backupsDir.DIRECTORY_SEPARATOR.'pre-restore-*.sqlite'))->toBe([])
        ->and((array) glob($this->stagingDir.DIRECTORY_SEPARATOR.'*'))->toBe([]);
});

// The positive control. Without it, a comparison that refused everything would
// read exactly like one that refuses the right thing.
it('restores a backup whose schema changes match this build exactly', function (): void {
    $names = generationNamesThisBuildHas();

    // Without this the case passes on an empty set matching an empty set: a
    // migrator that registered no paths would answer none, the comparison
    // would find nothing to differ about, and a restore that checks nothing
    // reads exactly like one that checked and agreed.
    expect($names)->toHaveCount(count(glob(base_path('Modules/*/Database/Migrations/*.php')) ?: []) + count(glob(base_path('database/migrations/*.php')) ?: []));

    $source = generationSourceRecording($names);
    $this->sources = [...$this->sources, $source];

    $this->artisan('db:restore', ['path' => $source, '--confirm' => true])
        ->doesntExpectOutputToContain('Brought the backup forward')
        ->assertSuccessful();

    $restored = (new PDO('sqlite:'.$this->livePath))
        ->query('SELECT count(*) FROM migrations')
        ->fetchColumn();

    expect((int) $restored)->toBe(count($names));
});

// A file recording none makes no claim to check. That is every fixture, every
// hand-built database, and any backup written before migrations were tracked;
// refusing them would strand a file over a question it never answered, and
// migrating them would run this build's whole set over a shape nothing knows.
it('restores a backup that records no schema changes at all', function (): void {
    $source = RealSqliteFixture::create('generation-unstamped');
    $this->sources = [...$this->sources, $source];

    $this->artisan('db:restore', ['path' => $source, '--confirm' => true])
        ->doesntExpectOutputToContain('Brought the backup forward')
        ->assertSuccessful();
});

// The command is the operator's path. This is the reader's, and it is the only
// one a phone has — where an older backup is the common case rather than the
// corner, because /mobile/restore is reached from whatever version the store
// is serving.
it('brings an older backup forward on the encrypted path the screens use', function (): void {
    $carried = generationNamesThisBuildHas();
    $this->shipped = [...$this->shipped, generationBuildAlsoShips(
        '2099_01_01_000000_a_table_the_encrypted_restore_adds',
        "Illuminate\\Support\\Facades\\Schema::create('a_table_the_encrypted_restore_adds', function (\$table): void { \$table->id(); });",
    )];

    $source = generationSourceRecording($carried);
    $this->sources = [...$this->sources, $source];
    $encrypted = $source.'.enc';
    $this->sources = [...$this->sources, $encrypted];
    (new BackupEncryptor(new CheapKdfCost))->encrypt($source, $encrypted, 'pw');

    $snapshot = app(RestoreEncryptedBackup::class)($encrypted, 'pw');

    /** @var string $livePath */
    $livePath = $this->livePath;

    expect(generationTablesIn($livePath))->toContain('a_table_the_encrypted_restore_adds')
        ->and(is_file($snapshot))->toBeTrue()
        ->and((array) glob($this->stagingDir.DIRECTORY_SEPARATOR.'*'))->toBe([]);
});

it('refuses an encrypted restore whose forward run fails, before writing a snapshot', function (): void {
    $carried = generationNamesThisBuildHas();
    $this->shipped = [...$this->shipped, generationBuildAlsoShips(
        '2099_01_01_000000_a_migration_that_fails_on_the_encrypted_path',
        "Illuminate\\Support\\Facades\\Schema::create('a_table_the_failed_encrypted_run_added', function (\$table): void { \$table->id(); });\n"
        ."                throw new RuntimeException('the half of it that does not apply');",
    )];

    $source = generationSourceRecording($carried);
    $this->sources = [...$this->sources, $source];
    $encrypted = $source.'.enc';
    $this->sources = [...$this->sources, $encrypted];
    (new BackupEncryptor(new CheapKdfCost))->encrypt($source, $encrypted, 'pw');

    /** @var string $livePath */
    $livePath = $this->livePath;
    $before = (string) hash_file('sha256', $livePath);

    expect(fn () => app(RestoreEncryptedBackup::class)($encrypted, 'pw'))
        ->toThrow(BackupCouldNotBeBroughtUpToDateException::class);

    expect(hash_file('sha256', $livePath))->toBe($before)
        ->and(generationTablesIn($livePath))->not->toContain('a_table_the_failed_encrypted_run_added')
        ->and((array) glob($this->backupsDir.DIRECTORY_SEPARATOR.'pre-restore-*.sqlite'))->toBe([])
        ->and((array) glob($this->stagingDir.DIRECTORY_SEPARATOR.'*'))->toBe([]);
});

// The reader is told which of the two it is, because one of them is worth
// their trying again and the other is only worth their updating Beatrax.
it('tells the two refusals apart in the reader own words', function (): void {
    $newer = RestoreRefusal::forThrowable(new BackupFromANewerBuildException('machine text', ['x']));
    $failed = RestoreRefusal::forThrowable(new BackupCouldNotBeBroughtUpToDateException('machine text', 3));

    expect($newer)->toBe(RestoreRefusal::FromANewerBuild)
        ->and($failed)->toBe(RestoreRefusal::CouldNotBeBroughtUpToDate)
        ->and($newer->sentence())->toContain('newer version of Beatrax')
        ->and($failed->sentence())->toContain('could not update this backup')
        ->and($newer->sentence())->not->toBe($failed->sentence())
        ->and($newer->sentence())->not->toContain('machine text')
        ->and($failed->sentence())->not->toContain('machine text');
});
