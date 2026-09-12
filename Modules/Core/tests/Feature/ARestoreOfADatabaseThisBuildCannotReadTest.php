<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Backup\BackupFromANewerBuildException;
use Modules\Core\Internal\Backup\BackupFromAnOlderBuildException;
use Modules\Core\Public\Enums\RestoreRefusal;
use Modules\Core\Public\Services\UserDataPathService;
use Tests\Helpers\LiveSqliteConnection;
use Tests\Helpers\RealSqliteFixture;

// A backup carries a database, and a database has a shape the code around it
// expects. Restoring one whose shape does not match produced no error and no
// refusal: the swap succeeded, integrity_check passed, the command printed
// success, and the application then ran against a schema its own code did not
// match. Nothing looked, in either direction — and a pending-migration check
// cannot see the newer one, because it asks whether each migration the build
// HAS was recorded as run, which a newer database answers yes to for all of
// them.

beforeEach(function (): void {
    $this->livePath = RealSqliteFixture::create('generation-live');
    LiveSqliteConnection::pointAt($this->app, $this->livePath);

    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-generation-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage';
    $this->backupsDir = $this->storageRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $this->downMarker = (new UserDataPathService)->framework('down');
    $files->ensureDirectoryExists(dirname($this->downMarker));
    $files->put($this->downMarker, '');

    $this->sources = [];
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

    /** @var string $storageRoot */
    $storageRoot = $this->storageRoot;
    if (is_dir($storageRoot)) {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storageRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($storageRoot);
        @rmdir(dirname($storageRoot));
    }

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

it('refuses a backup missing a schema change this build has run', function (): void {
    $here = generationNamesThisBuildHas();
    array_pop($here);

    $source = generationSourceRecording($here);
    $this->sources = [...$this->sources, $source];

    /** @var string $livePath */
    $livePath = $this->livePath;
    $before = (string) hash_file('sha256', $livePath);

    $this->artisan('db:restore', ['path' => $source, '--confirm' => true])
        ->expectsOutputToContain('older version of Beatrax')
        ->assertExitCode(1);

    expect(hash_file('sha256', $livePath))->toBe($before);
});

// The positive control. Without it, a comparison that refused everything would
// read exactly like one that refuses the right two things.
it('restores a backup whose schema changes match this build exactly', function (): void {
    $source = generationSourceRecording(generationNamesThisBuildHas());
    $this->sources = [...$this->sources, $source];

    $this->artisan('db:restore', ['path' => $source, '--confirm' => true])
        ->assertSuccessful();

    $restored = (new PDO('sqlite:'.$this->livePath))
        ->query('SELECT count(*) FROM migrations')
        ->fetchColumn();

    expect((int) $restored)->toBe(count(generationNamesThisBuildHas()));
});

// A file recording none makes no claim to check. That is every fixture, every
// hand-built database, and any backup written before migrations were tracked;
// refusing them would strand a file over a question it never answered.
it('restores a backup that records no schema changes at all', function (): void {
    $source = RealSqliteFixture::create('generation-unstamped');
    $this->sources = [...$this->sources, $source];

    $this->artisan('db:restore', ['path' => $source, '--confirm' => true])
        ->assertSuccessful();
});

// The reader is told which direction it is, because installing a newer build
// answers one of them and nothing answers the other.
it('tells the two directions apart in the reader own words', function (): void {
    $newer = RestoreRefusal::forThrowable(new BackupFromANewerBuildException('machine text', ['x']));
    $older = RestoreRefusal::forThrowable(new BackupFromAnOlderBuildException('machine text', ['x']));

    expect($newer)->toBe(RestoreRefusal::FromANewerBuild)
        ->and($older)->toBe(RestoreRefusal::FromAnOlderBuild)
        ->and($newer->sentence())->toContain('newer version of Beatrax')
        ->and($older->sentence())->toContain('older version of Beatrax')
        ->and($newer->sentence())->not->toBe($older->sentence())
        ->and($newer->sentence())->not->toContain('machine text');
});
