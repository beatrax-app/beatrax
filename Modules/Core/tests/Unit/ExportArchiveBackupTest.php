<?php

declare(strict_types=1);

use Modules\Core\Internal\Backup\ExportArchiveBackup;
use Modules\Core\Public\Exceptions\BackupFormatException;
use Modules\Core\Public\Exceptions\BackupIoException;

// Every refusal here is a sentence a reader can act on, so each one is reached
// on purpose. The archive is built byte by byte rather than through a writer:
// a header this application never emits is exactly what has to be refused, and
// no writer here can be asked to produce one.

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-eab-'.bin2hex(random_bytes(8));
    @mkdir($this->dir, 0o700, true);
});

afterEach(function (): void {
    /** @var string $dir */
    $dir = $this->dir;
    foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $entry) {
        @unlink((string) $entry);
    }
    @rmdir($dir);
});

/**
 * A single-entry zip written by hand, so a field can hold what no writer here
 * would ever put in it.
 */
function handRolledArchive(
    string $directory,
    string $name,
    string $payload,
    int $method = 0,
    int $flags = 0,
    ?int $declaredUncompressed = null,
    ?int $declaredCompressed = null,
): string {
    $header = "PK\x03\x04"
        .pack('v', 20)
        .pack('v', $flags)
        .pack('v', $method)
        .pack('v', 0)
        .pack('v', 0)
        .pack('V', crc32($payload))
        .pack('V', $declaredCompressed ?? strlen($payload))
        .pack('V', $declaredUncompressed ?? strlen($payload))
        .pack('v', strlen($name))
        .pack('v', 0);

    $path = $directory.DIRECTORY_SEPARATOR.'hand-rolled-'.bin2hex(random_bytes(4)).'.zip';
    file_put_contents($path, $header.$name.$payload);

    return $path;
}

function liftedContents(string $archivePath, string $directory): string
{
    $target = $directory.DIRECTORY_SEPARATOR.'lifted-'.bin2hex(random_bytes(4)).'.enc';
    (new ExportArchiveBackup)->liftBackupInto($archivePath, $target);

    return (string) file_get_contents($target);
}

it('lifts a stored entry without asking zlib for anything', function (): void {
    /** @var string $dir */
    $dir = $this->dir;
    $payload = 'BTRXENC1'.random_bytes(512);

    $archive = handRolledArchive($dir, ExportArchiveBackup::ENTRY_PREFIX.'2026-09-05-101010'.ExportArchiveBackup::ENTRY_SUFFIX, $payload);

    expect(liftedContents($archive, $dir))->toBe($payload);
});

it('refuses an archive whose first entry is not named as a backup', function (): void {
    /** @var string $dir */
    $dir = $this->dir;
    $archive = handRolledArchive($dir, 'artefacts/artefacts_imports/statement.csv', 'date,amount');

    expect(fn () => liftedContents($archive, $dir))
        ->toThrow(BackupFormatException::class, 'holds no Beatrax backup');
});

// A local header that states nothing and defers to a trailing descriptor would
// be read as a zero-length backup, and the reader would be told their database
// is damaged. Neither writer here emits one; an archive that does is not ours.
it('refuses an entry that states its length in a trailing descriptor', function (): void {
    /** @var string $dir */
    $dir = $this->dir;
    $archive = handRolledArchive(
        $dir,
        ExportArchiveBackup::ENTRY_PREFIX.'2026-09-05-101010'.ExportArchiveBackup::ENTRY_SUFFIX,
        'payload',
        flags: 0x0008,
    );

    expect(fn () => liftedContents($archive, $dir))
        ->toThrow(BackupFormatException::class, 'trailing descriptor');
});

it('refuses a compression method it cannot read, and says which', function (): void {
    /** @var string $dir */
    $dir = $this->dir;
    $archive = handRolledArchive(
        $dir,
        ExportArchiveBackup::ENTRY_PREFIX.'2026-09-05-101010'.ExportArchiveBackup::ENTRY_SUFFIX,
        'payload',
        method: 93,
    );

    expect(fn () => liftedContents($archive, $dir))
        ->toThrow(BackupFormatException::class, 'compression method 93');
});

it('refuses an archive that stops before the end of the backup it declares', function (): void {
    /** @var string $dir */
    $dir = $this->dir;
    $archive = handRolledArchive(
        $dir,
        ExportArchiveBackup::ENTRY_PREFIX.'2026-09-05-101010'.ExportArchiveBackup::ENTRY_SUFFIX,
        'a short payload',
        declaredCompressed: 4096,
    );

    expect(fn () => liftedContents($archive, $dir))
        ->toThrow(BackupFormatException::class, 'stops before the end');
});

it('refuses a backup that comes out at a length its own header disagrees with', function (): void {
    /** @var string $dir */
    $dir = $this->dir;
    $archive = handRolledArchive(
        $dir,
        ExportArchiveBackup::ENTRY_PREFIX.'2026-09-05-101010'.ExportArchiveBackup::ENTRY_SUFFIX,
        'a payload of known length',
        declaredUncompressed: 9999,
    );

    expect(fn () => liftedContents($archive, $dir))
        ->toThrow(BackupFormatException::class, 'where its header declares 9999');
});

it('refuses a file that opens like an archive and then ends', function (): void {
    /** @var string $dir */
    $dir = $this->dir;
    $path = $dir.DIRECTORY_SEPARATOR.'truncated.zip';
    file_put_contents($path, "PK\x03\x04\x14\x00");

    $reader = new ExportArchiveBackup;

    expect($reader->isArchive($path))->toBeTrue()
        ->and(fn () => liftedContents($path, $dir))
        ->toThrow(BackupFormatException::class, 'does not open with a zip entry');
});

it('refuses to read an archive that is not there, and to write where it cannot', function (): void {
    /** @var string $dir */
    $dir = $this->dir;
    $reader = new ExportArchiveBackup;
    $archive = handRolledArchive($dir, ExportArchiveBackup::ENTRY_PREFIX.'2026-09-05-101010'.ExportArchiveBackup::ENTRY_SUFFIX, 'payload');

    expect(fn () => $reader->liftBackupInto($dir.DIRECTORY_SEPARATOR.'absent.zip', $dir.DIRECTORY_SEPARATOR.'out.enc'))
        ->toThrow(BackupIoException::class, 'could not be opened for reading')
        ->and(fn () => $reader->liftBackupInto($archive, $dir.DIRECTORY_SEPARATOR.'no-such-directory'.DIRECTORY_SEPARATOR.'out.enc'))
        ->toThrow(BackupIoException::class, 'restore staging area');
});
