<?php

declare(strict_types=1);

use Modules\Core\Internal\Backup\ExportEverythingArchive;
use Modules\Core\Public\Services\UserDataLocations;
use Modules\Core\Public\Services\UserDataPathService;
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
