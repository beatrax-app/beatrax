<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Config;
use Modules\Core\Internal\Backup\ArchiveWriterFactory;
use Modules\Core\Internal\Backup\BackupKeyMaterial;
use Modules\Core\Internal\Backup\ExportArchiveBackup;
use Modules\Core\Internal\Backup\ExportEverythingArchive;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Exceptions\BackupFormatException;
use Modules\Core\Public\Services\RestoreEncryptedBackup;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\OwnerOnlyPath;

// The one-click export is the only export the "Where is my data?" page offers,
// and it writes a `.zip`. Every restore surface took a bare `.enc`, so the
// application refused its own export and told the reader to pick a file it had
// never written for them. On a phone that screen is the whole route home from
// a wipe, and the build there carries no ext-zip to unpack it with.

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-roundtrip-'.bin2hex(random_bytes(8));
    $this->storageRoot = $this->root.DIRECTORY_SEPARATOR.'storage';
    @mkdir($this->root, 0o700, true);
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);

    $this->live = $this->root.DIRECTORY_SEPARATOR.'live.sqlite';
    roundTripSqlite($this->live, 'EXPORTED');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $this->live);
    $db->purge('sqlite');
});

afterEach(function (): void {
    Config::set('database.default', 'sqlite_testing');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');
    putenv('NATIVEPHP_STORAGE_PATH');

    /** @var string $root */
    $root = $this->root;
    $walk = @glob($root.'/{,*/,*/*/,*/*/*/,*/*/*/*/,*/*/*/*/*/}*', GLOB_BRACE) ?: [];
    foreach (array_reverse($walk) as $entry) {
        is_dir((string) $entry) ? @rmdir((string) $entry) : @unlink((string) $entry);
    }
    @rmdir($root);
});

function roundTripSqlite(string $path, string $marker): void
{
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE IF NOT EXISTS marker (val TEXT)');
    $pdo->exec('DELETE FROM marker');
    $pdo->exec("INSERT INTO marker (val) VALUES ('".$marker."')");
}

function roundTripMarker(string $path): string
{
    return (string) (new PDO('sqlite:'.$path))->query('SELECT val FROM marker')->fetchColumn();
}

function roundTripArchive(bool $zipExtensionAvailable = true): ExportEverythingArchive
{
    return new ExportEverythingArchive(
        app(DatabaseManager::class),
        app(FileEncryptor::class),
        app(BackupKeyMaterial::class),
        app(OwnerOnlyPath::class),
        new ArchiveWriterFactory(zipExtensionAvailable: $zipExtensionAvailable),
    );
}

function roundTripPlant(string $relative, string $contents): string
{
    $path = UserDataPathService::appPath($relative);
    @mkdir(dirname($path), 0o700, true);
    file_put_contents($path, $contents);

    return $path;
}

it('restores the database out of the archive the export handed the reader', function (): void {
    /** @var string $live */
    $live = $this->live;

    $zipPath = roundTripArchive()->build('a-good-passphrase', '2026-09-05-101010');
    roundTripSqlite($live, 'CHANGED-SINCE');

    $snapshot = app(RestoreEncryptedBackup::class)($zipPath, 'a-good-passphrase');

    expect(roundTripMarker($live))->toBe('EXPORTED')
        ->and(roundTripMarker($snapshot))->toBe('CHANGED-SINCE');
});

// The archive a phone writes goes through NativeZipWriter, because that build
// has no ext-zip. It is the shell that most needs the round trip to close: the
// phone cannot unpack a zip for the reader either.
it('restores an archive written on a build that has no ext-zip', function (): void {
    /** @var string $live */
    $live = $this->live;

    $zipPath = roundTripArchive(zipExtensionAvailable: false)->build('a-good-passphrase', '2026-09-05-101010');
    roundTripSqlite($live, 'CHANGED-SINCE');

    app(RestoreEncryptedBackup::class)($zipPath, 'a-good-passphrase');

    expect(roundTripMarker($live))->toBe('EXPORTED');
});

// The source documents in the archive are the reader's own files and are
// already on their machine. A restore that unpacked them would be writing files
// nobody asked it to write, at paths the archive rather than this application
// chose — which is the shape a zip-slip takes.
it('lifts the backup out of the archive and unpacks nothing else', function (): void {
    $statement = roundTripPlant('private/imports/1/statement-march.csv', "date,amount\n2026-03-01,-12.50\n");

    $zipPath = roundTripArchive()->build('a-good-passphrase', '2026-09-05-101010');
    @unlink($statement);

    app(RestoreEncryptedBackup::class)($zipPath, 'a-good-passphrase');

    $staged = glob(UserDataPathService::appPath('tmp-restore').DIRECTORY_SEPARATOR.'*') ?: [];

    expect(is_file($statement))->toBeFalse()
        ->and($staged)->toBe([])
        ->and(is_dir(UserDataPathService::appPath('tmp-restore').DIRECTORY_SEPARATOR.'artefacts'))->toBeFalse();
});

// Somebody else's zip opens here too, and a mis-parse of one would be reported
// as a damaged database — sending the reader to look for an earlier backup over
// a file that was never a backup at all.
it('refuses an archive that holds no backup of ours, and leaves the live file alone', function (): void {
    /** @var string $root */
    $root = $this->root;
    /** @var string $live */
    $live = $this->live;

    $foreign = $root.DIRECTORY_SEPARATOR.'somebody-elses-export.zip';
    $zip = new ZipArchive;
    expect($zip->open($foreign, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('Budget.yfull', '{"transactions":[]}');
    $zip->close();

    expect(fn () => app(RestoreEncryptedBackup::class)($foreign, 'a-good-passphrase'))
        ->toThrow(BackupFormatException::class, 'holds no Beatrax backup');

    // A refusal a reader retries, so the staging file the lift was about to
    // fill goes with it rather than leaving one empty 0600 file per attempt.
    $staged = glob(UserDataPathService::appPath('tmp-restore').DIRECTORY_SEPARATOR.'*') ?: [];

    expect(roundTripMarker($live))->toBe('EXPORTED')
        ->and($staged)->toBe([]);
});

it('tells an archive from a bare encrypted backup by its first four bytes', function (): void {
    /** @var string $root */
    $root = $this->root;

    $zipPath = roundTripArchive()->build('a-good-passphrase', '2026-09-05-101010');
    $bare = $root.DIRECTORY_SEPARATOR.'bare-backup.sqlite.enc';
    app(FileEncryptor::class)->encrypt($this->live, $bare, 'a-good-passphrase');

    $reader = new ExportArchiveBackup;

    expect($reader->isArchive($zipPath))->toBeTrue()
        ->and($reader->isArchive($bare))->toBeFalse()
        ->and($reader->isArchive($root.DIRECTORY_SEPARATOR.'nothing-here'))->toBeFalse();
});
