<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Backup\BackupKeyMaterial;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Public\Services\PortableKeyMaterial;
use Tests\Helpers\LiveSqliteConnection;
use Tests\Helpers\RealSqliteFixture;

// `db:backup` is the backup path the operator runbook calls supported, and the
// one the daily schedule runs. For a reader with encryption at rest the keys
// that open their notes, descriptions, counterparty names and IBANs are a file
// BESIDE the database, so a VACUUM INTO copy restored anywhere that file is not
// is a ledger of ciphertext — and the restore reports success. The encrypted
// download and the export archive both carry the keyring inside the snapshot;
// these two commands are the producer and the consumer that did not.

beforeEach(function (): void {
    $this->livePath = RealSqliteFixture::create('keyring-backup-live', [
        ...RealSqliteFixture::DEFAULT_SCHEMAS,
        'CREATE TABLE sync_encryption_state (user_id INTEGER PRIMARY KEY, current_epoch INTEGER)',
        'INSERT INTO sync_encryption_state (user_id, current_epoch) VALUES (1, 771122)',
    ]);

    LiveSqliteConnection::pointAt($this->app, $this->livePath);

    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-keyring-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage';
    $this->backupsDir = $this->storageRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);

    $this->keyring = (new PortableKeyMaterial)->keyringPath(1);
    @mkdir(dirname($this->keyring), 0o700, true);
    file_put_contents($this->keyring, supportedBackupKeyringBytes());

    // db:restore reads the maintenance marker through UserDataPathService, so
    // the file is written where that answers rather than through `artisan down`.
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $this->downMarker = (new UserDataPathService)->framework('down');
    $files->ensureDirectoryExists(dirname($this->downMarker));
    $files->put($this->downMarker, '');
});

afterEach(function (): void {
    LiveSqliteConnection::restore($this->app);

    /** @var string $livePath */
    $livePath = $this->livePath;
    RealSqliteFixture::cleanup($livePath);

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

function supportedBackupKeyringBytes(): string
{
    return 'the-only-copy-of-epoch-771122';
}

// A view where the carrier table goes. `DROP TABLE IF EXISTS` refuses a view
// by name, so packInto() throws a PDOException — deterministic on any runner,
// unlike a mode this process might be privileged enough to read past.
function supportedBackupPlantACarrierView(string $livePath): void
{
    (new PDO('sqlite:'.$livePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))
        ->exec('CREATE VIEW '.BackupKeyMaterial::TABLE.' AS SELECT 1 AS user_id, \'x\' AS keyring');
}

/** @return list<string> */
function supportedBackupTablesIn(string $path): array
{
    /** @var list<string> $tables */
    $tables = (new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))
        ->query("SELECT name FROM sqlite_master WHERE type = 'table'")
        ->fetchAll(PDO::FETCH_COLUMN);

    return $tables;
}

it('writes a backup that carries the key that opens it', function (): void {
    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $produced = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite');

    expect($produced)->toHaveCount(1);

    $carried = (new PDO('sqlite:'.(string) $produced[0], options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))
        ->query('SELECT user_id, keyring FROM '.BackupKeyMaterial::TABLE)
        ->fetchAll(PDO::FETCH_ASSOC);

    expect($carried)->toHaveCount(1)
        ->and((int) $carried[0]['user_id'])->toBe(1)
        ->and(base64_decode((string) $carried[0]['keyring'], true))->toBe(supportedBackupKeyringBytes());
});

it('puts the key back on a machine that does not have it, and leaves none in the ledger', function (): void {
    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $produced = (string) ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite'))[0];

    // The machine the restore lands on is not the one that made the backup: its
    // keyring directory is empty, which is every fresh install by definition.
    /** @var string $keyring */
    $keyring = $this->keyring;
    unlink($keyring);

    $this->artisan('db:restore', ['path' => $produced, '--confirm' => true])->assertSuccessful();

    /** @var string $livePath */
    $livePath = $this->livePath;

    expect(is_file($keyring))->toBeTrue('The restore left the ledger sealed and the key behind.')
        ->and(file_get_contents($keyring))->toBe(supportedBackupKeyringBytes())
        ->and(fileperms($keyring) & 0o077)->toBe(0)
        ->and(supportedBackupTablesIn($livePath))->not->toContain(BackupKeyMaterial::TABLE)
        ->and(supportedBackupTablesIn($livePath))->toContain('sync_encryption_state');
});

// The operator's backup is evidence, and lifting the keys out of a file edits
// it. A restore that consumed its own source would leave a file that restores
// the ledger once and the keys never again.
it('does not edit the backup file it restored', function (): void {
    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $produced = (string) ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite'))[0];
    $before = (string) hash_file('sha256', $produced);

    /** @var string $keyring */
    $keyring = $this->keyring;
    unlink($keyring);

    $this->artisan('db:restore', ['path' => $produced, '--confirm' => true])->assertSuccessful();

    expect(hash_file('sha256', $produced))->toBe($before)
        ->and(supportedBackupTablesIn($produced))->toContain(BackupKeyMaterial::TABLE);
});

// A file written before the keyring travelled inside it still restores.
// Refusing one would strand every backup an operator already holds.
it('restores a backup file that carries no keyring at all', function (): void {
    $legacy = RealSqliteFixture::create('keyring-legacy-source', [
        'CREATE TABLE sync_encryption_state (user_id INTEGER PRIMARY KEY, current_epoch INTEGER)',
        'INSERT INTO sync_encryption_state (user_id, current_epoch) VALUES (1, 4242)',
    ]);

    $this->artisan('db:restore', ['path' => $legacy, '--confirm' => true])->assertSuccessful();

    /** @var string $livePath */
    $livePath = $this->livePath;
    $restored = (new PDO('sqlite:'.$livePath))->query('SELECT current_epoch FROM sync_encryption_state')->fetchColumn();

    expect((int) $restored)->toBe(4242);

    RealSqliteFixture::cleanup($legacy);
});

// The smart skip hashes the finished copy. Key material that is the same on
// two runs has to leave the digest the same, or a quiet day writes a second
// copy and the retention window is spent on duplicates.
it('still skips a run whose contents have not changed', function (): void {
    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();
    $this->artisan('db:backup')
        ->expectsOutputToContain('Skipped')
        ->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;

    expect((array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite'))->toHaveCount(1);
});

// The install with no sealed columns is the majority, and it pays for none of
// this: no carrier table, so the finished copy is the plain VACUUM INTO the
// smart skip has always hashed, and the swap reads the operator's file rather
// than a staged copy of it.
it('carries nothing, and changes nothing, for an install with no keyring', function (): void {
    /** @var string $keyring */
    $keyring = $this->keyring;
    unlink($keyring);

    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $produced = (string) ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite'))[0];

    expect(supportedBackupTablesIn($produced))->not->toContain(BackupKeyMaterial::TABLE);

    $this->artisan('db:restore', ['path' => $produced, '--confirm' => true])->assertSuccessful();

    /** @var string $livePath */
    $livePath = $this->livePath;

    expect(is_file($keyring))->toBeFalse()
        ->and(supportedBackupTablesIn($livePath))->toContain('sync_encryption_state');
});

// The staged copy is the whole ledger in clear, and a staged database is three
// files: unlinking the one that is named leaves its `-wal` and `-shm` holding
// the newest of it in the staging directory.
it('leaves nothing of the ledger in the staging directory', function (): void {
    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $produced = (string) ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite'))[0];

    $this->artisan('db:restore', ['path' => $produced, '--confirm' => true])->assertSuccessful();

    $staged = (array) glob(UserDataPathService::appPath('tmp-restore').DIRECTORY_SEPARATOR.'*');

    expect($staged)->toBe([]);
});

// The pre-restore snapshot is the documented undo, and a restore replaces the
// keyring on its way past. An undo that puts the rows back under a keyring
// that is no longer the active one restores a ledger nothing can read — so the
// snapshot carries the keys it is an undo of.
it('makes the pre-restore snapshot an undo of the keyring too', function (): void {
    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $produced = (string) ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite'))[0];

    // An epoch is appended after the backup was taken, so the machine's
    // keyring is no longer the one the backup carries.
    /** @var string $keyring */
    $keyring = $this->keyring;
    file_put_contents($keyring, 'the-keyring-with-the-epoch-added-later');

    $this->artisan('db:restore', ['path' => $produced, '--confirm' => true])->assertSuccessful();

    expect(file_get_contents($keyring))->toBe(supportedBackupKeyringBytes());

    $snapshots = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'pre-restore-*.sqlite');

    expect($snapshots)->toHaveCount(1);

    $this->artisan('db:restore', ['path' => (string) $snapshots[0], '--confirm' => true])->assertSuccessful();

    expect(file_get_contents($keyring))->toBe('the-keyring-with-the-epoch-added-later');
});

// The snapshot name is second-resolution and VACUUM INTO refuses an existing
// target. The documented undo is to restore the snapshot a failed restore just
// named, so two runs inside one second is the ordinary case — and it threw a
// raw query exception off the safety rail itself, after maintenance mode was
// taken.
it('takes a second pre-restore snapshot inside the same second', function (): void {
    app()->instance(Clock::class, new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-09-12 12:00:00');
        }
    });

    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $produced = (string) ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite'))[0];

    $this->artisan('db:restore', ['path' => $produced, '--confirm' => true])->assertSuccessful();
    $this->artisan('db:restore', ['path' => $produced, '--confirm' => true])->assertSuccessful();

    expect((array) glob($backupsDir.DIRECTORY_SEPARATOR.'pre-restore-*.sqlite'))->toHaveCount(2);
});

// A snapshot that cannot take the key material is an incomplete backup, not a
// quiet success: the copy is deleted, the operator gets the standing
// `backup_corrupt` alert naming the phase, and no file is left behind claiming
// to be a backup.
//
// The trigger is a VIEW named where the carrier table goes: `DROP TABLE IF
// EXISTS` refuses a view, so the throw arrives as a PDOException rather than
// one of ours — which is the arm that must name the class instead of a message
// carrying the statement and its bindings.
it('refuses the backup when the snapshot cannot take the key material', function (): void {
    supportedBackupPlantACarrierView($this->livePath);

    $this->artisan('db:backup', ['--force' => true])
        ->expectsOutputToContain('its backup files could not be written')
        ->assertExitCode(1);

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;

    expect((array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite'))->toBe([])
        ->and((array) glob($backupsDir.DIRECTORY_SEPARATOR.'*.partial'))->toBe([]);

    $alert = SystemAlert::query()->where('kind', 'backup_corrupt')->latest('id')->first();

    expect($alert)->not->toBeNull()
        ->and($alert->metadata['phase'] ?? null)->toBe('pack_key_material')
        ->and($alert->metadata['cause'] ?? null)->toBe('write_failed')
        ->and($alert->metadata['reason'] ?? null)->toBe('PDOException');
});

// The same refusal on the restore's own pre-restore snapshot. It happens
// before the live database is touched, so it costs the reader nothing — and
// an undo that could not carry the keys is not an undo.
it('refuses the restore when the pre-restore snapshot cannot carry the keys', function (): void {
    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $produced = (string) ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite'))[0];

    /** @var string $livePath */
    $livePath = $this->livePath;
    supportedBackupPlantACarrierView($livePath);
    $before = (string) hash_file('sha256', $livePath);

    $this->artisan('db:restore', ['path' => $produced, '--confirm' => true])
        ->expectsOutputToContain('Restore refused')
        ->assertExitCode(1);

    expect(hash_file('sha256', $livePath))->toBe($before);
});

// A carrier row that will not base64-decode is a refusal, and the staged copy
// goes with it rather than sitting in the staging directory as a whole ledger.
it('refuses a restore whose carried keyring will not decode', function (): void {
    $source = RealSqliteFixture::create('keyring-undecodable', [
        'CREATE TABLE sync_encryption_state (user_id INTEGER PRIMARY KEY, current_epoch INTEGER)',
        'CREATE TABLE '.BackupKeyMaterial::TABLE.' (user_id INTEGER PRIMARY KEY, keyring TEXT NOT NULL)',
        'INSERT INTO '.BackupKeyMaterial::TABLE." (user_id, keyring) VALUES (1, '')",
    ]);

    /** @var string $livePath */
    $livePath = $this->livePath;
    $before = (string) hash_file('sha256', $livePath);

    $this->artisan('db:restore', ['path' => $source, '--confirm' => true])
        ->expectsOutputToContain('Restore refused')
        ->assertExitCode(1);

    expect(hash_file('sha256', $livePath))->toBe($before)
        ->and((array) glob(UserDataPathService::appPath('tmp-restore').DIRECTORY_SEPARATOR.'*'))->toBe([]);

    RealSqliteFixture::cleanup($source);
});

// The staging directory is where the whole ledger lands in clear, so a mode
// that will not settle is a refused restore rather than a copy left somewhere
// this application could not narrow.
it('refuses a restore it cannot stage the copy for', function (): void {
    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $produced = (string) ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite'))[0];

    // A regular file where the staging directory has to be: mkdir cannot make
    // it and is_dir never becomes true, which is the refusal OwnerOnlyPath
    // answers with.
    $staging = UserDataPathService::appPath('tmp-restore');
    file_put_contents(rtrim($staging, '/'), '');

    /** @var string $livePath */
    $livePath = $this->livePath;
    $before = (string) hash_file('sha256', $livePath);

    $this->artisan('db:restore', ['path' => $produced, '--confirm' => true])
        ->expectsOutputToContain('could not be made owner-only')
        ->assertExitCode(1);

    expect(hash_file('sha256', $livePath))->toBe($before);
});

// The copy itself failing leaves nothing behind either: the same discard runs,
// and the refusal names the class rather than a message a query could have
// written the ledger into.
it('refuses a restore whose staged copy will not write', function (): void {
    $refusesToCopy = new class extends Filesystem
    {
        public function copy($path, $target): bool
        {
            return false;
        }
    };

    // Both names and before the first artisan call: the container aliases
    // `files` to the class, and the console application resolves every command
    // it holds the moment one of them is run.
    app()->instance(Filesystem::class, $refusesToCopy);
    app()->instance('files', $refusesToCopy);

    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $produced = (string) ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite'))[0];

    $this->artisan('db:restore', ['path' => $produced, '--confirm' => true])
        ->expectsOutputToContain('could not be staged for the swap')
        ->assertExitCode(1);

    expect((array) glob(UserDataPathService::appPath('tmp-restore').DIRECTORY_SEPARATOR.'*'))->toBe([]);
});
