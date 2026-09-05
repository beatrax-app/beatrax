<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Internal\Backup\ArchiveWriterFactory;
use Modules\Core\Internal\Backup\BackupKeyMaterial;
use Modules\Core\Internal\Backup\ExportEverythingArchive;
use Modules\Core\Internal\Storage\UserDataLocations;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\OwnerOnlyPath;
use Tests\Helpers\LiveSqliteConnection;
use Tests\Helpers\RealSqliteFixture;

// The backup is the database and nothing else, so an export that shipped only
// the backup shipped none of the reader's own documents: the statements they
// imported, the mail the scanner pulled in, the receipts they dropped in.

beforeEach(function (): void {
    $this->sourcePath = RealSqliteFixture::create('export-everything-source');
    LiveSqliteConnection::pointAt($this->app, $this->sourcePath);

    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-export-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    LiveSqliteConnection::restore($this->app);
    putenv('NATIVEPHP_STORAGE_PATH');

    /** @var string $sourcePath */
    $sourcePath = $this->sourcePath;
    RealSqliteFixture::cleanup($sourcePath);

    /** @var string $storageRoot */
    $storageRoot = $this->storageRoot;
    $walk = @glob($storageRoot.'/{,*/,*/*/,*/*/*/,*/*/*/*/,*/*/*/*/*/}*', GLOB_BRACE) ?: [];
    foreach (array_reverse($walk) as $entry) {
        is_dir((string) $entry) ? @rmdir((string) $entry) : @unlink((string) $entry);
    }
});

/** @return list<string> every entry name inside the archive */
function exportArchiveEntries(string $zipPath): array
{
    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (is_string($name)) {
            $names[] = $name;
        }
    }
    $zip->close();

    sort($names);

    return $names;
}

function plantExportArtefact(string $relative, string $contents): string
{
    $path = UserDataPathService::appPath($relative);
    @mkdir(dirname($path), 0o700, true);
    file_put_contents($path, $contents);

    return $path;
}

it('bundles the encrypted database and every artefact directory into one archive', function (): void {
    plantExportArtefact('private/imports/1/statement-march.csv', "date,amount\n2026-03-01,-12.50\n");
    plantExportArtefact('inbox/1/7/2026/09/a-receipt.eml', "Subject: Your receipt\r\n\r\nThanks.\r\n");
    plantExportArtefact('inbox-drop/1/dropped-invoice.pdf', '%PDF-1.4 fixture');

    /** @var ExportEverythingArchive $archive */
    $archive = $this->app->make(ExportEverythingArchive::class);

    $zipPath = $archive->build('a-good-passphrase', '2026-09-04-120000');

    expect(is_file($zipPath))->toBeTrue();

    $entries = exportArchiveEntries($zipPath);

    // Assert the archive is non-empty before asserting what is in it: a build
    // that produced nothing would otherwise satisfy every "does not contain"
    // check below.
    expect($entries)->toHaveCount(4)
        ->and($entries)->toContain('beatrax-backup-2026-09-04-120000.sqlite.enc')
        ->and($entries)->toContain('artefacts/'.UserDataLocations::ARTEFACTS_IMPORTS.'/1/statement-march.csv')
        ->and($entries)->toContain('artefacts/'.UserDataLocations::ARTEFACTS_MAIL.'/1/7/2026/09/a-receipt.eml')
        ->and($entries)->toContain('artefacts/'.UserDataLocations::ARTEFACTS_DROP.'/1/dropped-invoice.pdf');

    @unlink($zipPath);
});

it('hands back the reader their own documents, byte for byte', function (): void {
    $statement = "date,amount\n2026-03-01,-12.50\n";
    plantExportArtefact('private/imports/1/statement-march.csv', $statement);

    /** @var ExportEverythingArchive $archive */
    $archive = $this->app->make(ExportEverythingArchive::class);
    $zipPath = $archive->build('a-good-passphrase', '2026-09-04-120000');

    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    $extracted = $zip->getFromName('artefacts/'.UserDataLocations::ARTEFACTS_IMPORTS.'/1/statement-march.csv');
    $zip->close();

    expect($extracted)->toBe($statement);

    @unlink($zipPath);
});

it('never writes the database into the archive in the clear', function (): void {
    /** @var ExportEverythingArchive $archive */
    $archive = $this->app->make(ExportEverythingArchive::class);
    $zipPath = $archive->build('a-good-passphrase', '2026-09-04-120000');

    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    $backup = $zip->getFromName('beatrax-backup-2026-09-04-120000.sqlite.enc');
    $zip->close();

    expect($backup)->toBeString()
        ->and($backup)->not->toBe('')
        ->and(str_starts_with((string) $backup, 'SQLite format 3'))->toBeFalse();

    @unlink($zipPath);
});

it('leaves no plaintext snapshot behind in the staging directory', function (): void {
    /** @var ExportEverythingArchive $archive */
    $archive = $this->app->make(ExportEverythingArchive::class);
    $zipPath = $archive->build('a-good-passphrase', '2026-09-04-120000');

    $staged = glob(UserDataPathService::appPath('tmp-backups').DIRECTORY_SEPARATOR.'*') ?: [];
    $leftovers = array_values(array_filter(
        array_map(static fn (string $p): string => basename($p), array_map('strval', $staged)),
        static fn (string $name): bool => str_ends_with($name, '.sqlite'),
    ));

    expect($leftovers)->toBe([]);

    @unlink($zipPath);
});

it('exports for a reader who has imported nothing at all', function (): void {
    /** @var ExportEverythingArchive $archive */
    $archive = $this->app->make(ExportEverythingArchive::class);

    $zipPath = $archive->build('a-good-passphrase', '2026-09-04-120000');

    expect(exportArchiveEntries($zipPath))->toBe(['beatrax-backup-2026-09-04-120000.sqlite.enc']);

    @unlink($zipPath);
});

// The phone's PHP build carries no ext-zip, so the export runs through the
// native writer there and nowhere else. Building with the answer a phone gives
// is the only way that branch is reached on a machine that has the extension.
it('builds the same archive on a build without ext-zip', function (): void {
    plantExportArtefact('private/imports/1/statement-march.csv', "date,amount\n2026-03-01,-12.50\n");
    plantExportArtefact('inbox/1/7/2026/09/a-receipt.eml', "Subject: Your receipt\r\n\r\nThanks.\r\n");

    $archive = new ExportEverythingArchive(
        $this->app->make(DatabaseManager::class),
        $this->app->make(FileEncryptor::class),
        $this->app->make(BackupKeyMaterial::class),
        $this->app->make(OwnerOnlyPath::class),
        new ArchiveWriterFactory(zipExtensionAvailable: false),
    );

    $zipPath = $archive->build('a-good-passphrase', '2026-09-04-120000');

    $entries = exportArchiveEntries($zipPath);

    expect($entries)->toHaveCount(3)
        ->and($entries)->toContain('beatrax-backup-2026-09-04-120000.sqlite.enc')
        ->and($entries)->toContain('artefacts/'.UserDataLocations::ARTEFACTS_IMPORTS.'/1/statement-march.csv')
        ->and($entries)->toContain('artefacts/'.UserDataLocations::ARTEFACTS_MAIL.'/1/7/2026/09/a-receipt.eml');

    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    $extracted = $zip->getFromName('artefacts/'.UserDataLocations::ARTEFACTS_IMPORTS.'/1/statement-march.csv');
    $zip->close();

    expect($extracted)->toBe("date,amount\n2026-03-01,-12.50\n");

    @unlink($zipPath);
});

// A symlink in an artefact directory is a path out of the tree the reader never
// put there. Following one would put whatever it points at inside an archive
// the reader is about to hand somebody.
it('does not follow a symlink out of an artefact directory', function (): void {
    $real = plantExportArtefact('private/imports/1/statement-march.csv', "date,amount\n2026-03-01,-12.50\n");
    $secret = plantExportArtefact('secrets/provider-token.txt', 'a-token-nobody-asked-to-export');
    symlink($secret, UserDataPathService::appPath('private/imports/1/escape.txt'));

    /** @var ExportEverythingArchive $archive */
    $archive = $this->app->make(ExportEverythingArchive::class);
    $zipPath = $archive->build('a-good-passphrase', '2026-09-04-120000');

    $entries = exportArchiveEntries($zipPath);

    expect($entries)->toHaveCount(2)
        ->and($entries)->toContain('artefacts/'.UserDataLocations::ARTEFACTS_IMPORTS.'/1/statement-march.csv')
        ->and($entries)->not->toContain('artefacts/'.UserDataLocations::ARTEFACTS_IMPORTS.'/1/escape.txt')
        ->and(is_file($real))->toBeTrue();

    @unlink($zipPath);
});

// The archive is a copy with a boundary, and the failure this guards against
// already happened once on the packagers' side: a bundle shipped `storage/app`
// wholesale and carried the builder's signing key out with it. Connector
// credentials sit one directory from the source documents, so "everything under
// the storage root" is the sweep that must never be written.
it('leaves every location withheld from the export out of the archive', function (): void {
    plantExportArtefact('private/imports/1/statement-march.csv', "date,amount\n2026-03-01,-12.50\n");

    $planted = [
        'secrets/open-banking.json' => '{"client_secret":"a-connector-credential"}',
        'backups/beatrax-2026-09-01-030000.sqlite' => 'SQLite format 3'."\0",
        'tmp-backups/beatrax-export-leftover.sqlite' => 'a working artefact from an earlier run',
        'sync/gdk/1.enc' => 'the keyring that opens the sealed columns',
    ];
    foreach ($planted as $relative => $contents) {
        plantExportArtefact($relative, $contents);
    }

    /** @var ExportEverythingArchive $archive */
    $archive = $this->app->make(ExportEverythingArchive::class);
    $zipPath = $archive->build('a-good-passphrase', '2026-09-04-120000');

    $entries = exportArchiveEntries($zipPath);

    // The one entry that must be there, asserted first: every "is not in the
    // archive" claim below is true of an archive that was never built.
    expect($entries)->toHaveCount(2)
        ->and($entries)->toContain('artefacts/'.UserDataLocations::ARTEFACTS_IMPORTS.'/1/statement-march.csv');

    $joined = implode("\n", $entries);
    foreach (array_keys($planted) as $relative) {
        expect($joined)->not->toContain(basename($relative));
    }

    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    foreach ($planted as $relative => $contents) {
        expect($zip->locateName(basename($relative), ZipArchive::FL_NODIR))->toBeFalse();
    }
    $zip->close();

    @unlink($zipPath);
});

// Every location the page shows is either carried or withheld, by name. A
// location added to the inventory and to neither list would be swept in or left
// out by whichever branch happened to reach it first.
it('classifies every location in the inventory as carried or withheld', function (): void {
    $all = array_keys(UserDataLocations::all());
    $carried = array_keys(UserDataLocations::artefacts());
    $withheld = array_keys(UserDataLocations::withheldFromExport());

    sort($all);
    $classified = array_merge($carried, $withheld);
    sort($classified);

    expect($classified)->toBe($all)
        ->and(array_intersect($carried, $withheld))->toBe([]);
});

it('withholds the connector credentials directory by name, not by accident', function (): void {
    expect(UserDataLocations::withheldFromExport())
        ->toHaveKey(UserDataLocations::SECRETS)
        ->and(UserDataLocations::artefacts())
        ->not->toHaveKey(UserDataLocations::SECRETS);
});
