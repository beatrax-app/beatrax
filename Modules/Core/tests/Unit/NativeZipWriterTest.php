<?php

declare(strict_types=1);

use Modules\Core\Internal\Backup\NativeZipWriter;
use Modules\Core\Public\Exceptions\BackupIoException;

function nativeZipWriterWorkspace(): string
{
    $workspace = sys_get_temp_dir().'/native-zip-writer-'.uniqid('', true);
    mkdir($workspace, 0o700, true);

    return $workspace;
}

/**
 * @param  array<string, string>  $entries  entryName => contents
 * @return string the path of the archive NativeZipWriter wrote
 */
function nativeZipWriterPack(array $entries): string
{
    $workspace = nativeZipWriterWorkspace();
    $path = $workspace.'/export.zip';

    $writer = new NativeZipWriter;
    $writer->open($path);

    $index = 0;
    foreach ($entries as $name => $contents) {
        $source = $workspace.'/source-'.$index++;
        file_put_contents($source, $contents);
        $writer->addFile($source, $name);
    }

    $writer->finish();

    return $path;
}

/**
 * @return list<string> every entry name the archive lists, in the order it lists them
 */
function nativeZipWriterNamesIn(ZipArchive $zip): array
{
    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = (string) $zip->getNameIndex($index);
    }

    return $names;
}

it('NativeZipWriter: writes an archive ext-zip opens, entry for entry', function (): void {
    $entries = [
        'beatrax-backup-2026-09-05.sqlite.enc' => random_bytes(320 * 1024),
        'notes.txt' => "a small text file\nwith two lines\n",
        'artefacts/imports/1/statement.csv' => "date,payee,amount\n2026-01-01,Shop,-12.50\n",
        'artefacts/receipts/café-ünïcode-Ω.txt' => 'a receipt filed under an accented payee',
    ];

    $path = nativeZipWriterPack($entries);

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    expect($zip->numFiles)->toBe(count($entries));
    expect(nativeZipWriterNamesIn($zip))->toBe(array_keys($entries));

    foreach ($entries as $name => $contents) {
        expect($zip->getFromName($name))->toBe($contents, "entry {$name} did not come back byte-for-byte");
    }

    $zip->close();
});

it('NativeZipWriter: writes an archive the system unzip verifies', function (): void {
    $unzip = function_exists('shell_exec') ? trim((string) shell_exec('command -v unzip 2>/dev/null')) : '';
    if ($unzip === '') {
        $this->markTestSkipped('this machine has no system unzip to check the archive with');
    }

    $path = nativeZipWriterPack([
        'beatrax-backup.sqlite.enc' => random_bytes(200 * 1024),
        'artefacts/imports/1/statement.csv' => str_repeat("date,payee,amount\n", 500),
        'artefacts/receipts/café-ünïcode-Ω.txt' => 'a receipt filed under an accented payee',
    ]);

    $report = (string) shell_exec(escapeshellarg($unzip).' -t '.escapeshellarg($path).' 2>&1');

    expect($report)->toContain('No errors detected');
});

it('NativeZipWriter: writes an archive with no entries that is still a valid zip', function (): void {
    $path = nativeZipWriterWorkspace().'/empty.zip';

    $writer = new NativeZipWriter;
    $writer->open($path);
    $writer->finish();

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    expect($zip->numFiles)->toBe(0);
    $zip->close();
});

it('NativeZipWriter: round-trips a zero-byte source and the entry after it', function (): void {
    $path = nativeZipWriterPack([
        'empty.txt' => '',
        'after-it.txt' => 'the entry written after the empty one',
    ]);

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    expect($zip->numFiles)->toBe(2);

    /** @var array{size: int} $stat */
    $stat = $zip->statName('empty.txt');
    expect($stat['size'])->toBe(0);
    expect($zip->getFromName('after-it.txt'))->toBe('the entry written after the empty one');

    $zip->close();
});

it('NativeZipWriter: stores an entry name with forward slashes and no leading slash', function (): void {
    $workspace = nativeZipWriterWorkspace();
    $source = $workspace.'/statement.csv';
    file_put_contents($source, "date,payee\n2026-01-01,Shop\n");
    $path = $workspace.'/export.zip';

    $writer = new NativeZipWriter;
    $writer->open($path);
    $writer->addFile($source, '/artefacts\\imports\\1\\statement.csv');
    $writer->finish();

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    expect(nativeZipWriterNamesIn($zip))->toBe(['artefacts/imports/1/statement.csv']);
    $zip->close();
});

it('NativeZipWriter: refuses a source file that is not there', function (): void {
    $workspace = nativeZipWriterWorkspace();

    $writer = new NativeZipWriter;
    $writer->open($workspace.'/export.zip');

    expect(fn () => $writer->addFile($workspace.'/nothing-here.sqlite', 'beatrax-backup.sqlite.enc'))
        ->toThrow(BackupIoException::class);

    $writer->finish();
});

it('NativeZipWriter: refuses a path it cannot open for writing', function (): void {
    $writer = new NativeZipWriter;

    expect(fn () => $writer->open(nativeZipWriterWorkspace().'/no-such-directory/export.zip'))
        ->toThrow(BackupIoException::class);
});

it('NativeZipWriter: refuses to add a file before it is opened or after it is finished', function (): void {
    $workspace = nativeZipWriterWorkspace();
    $source = $workspace.'/notes.txt';
    file_put_contents($source, 'one line');

    $writer = new NativeZipWriter;
    expect(fn () => $writer->addFile($source, 'notes.txt'))->toThrow(BackupIoException::class);

    $writer->open($workspace.'/export.zip');
    $writer->addFile($source, 'notes.txt');
    $writer->finish();

    expect(fn () => $writer->addFile($source, 'later.txt'))->toThrow(BackupIoException::class);
});
