<?php

declare(strict_types=1);

use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Internal\Parsers\Support\ArchiveReader;
use Modules\Migration\Internal\Parsers\Support\ArchiveReaderFactory;
use Modules\Migration\Internal\Parsers\Support\NativeZipReader;
use Modules\Migration\Internal\Parsers\Support\ZipArchiveReader;
use Modules\Migration\Internal\Parsers\Support\ZipExtractor;

// ZipExtractor adds up what the index declares and refuses anything over
// 200MB. Nothing held the extraction to that number, so an entry was free to
// announce one byte and write a disk. The payload here is 2MB of one
// character, which deflates to about 2KB.
const LYING_ARCHIVE_PAYLOAD_BYTES = 2 * 1024 * 1024;

function lyingArchiveWithDeclaredSize(int $declared): string
{
    $path = sys_get_temp_dir().'/lying-archive-'.uniqid('', true).'.zip';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('Register.csv', str_repeat('a', LYING_ARCHIVE_PAYLOAD_BYTES));
    $zip->setCompressionName('Register.csv', ZipArchive::CM_DEFLATE);
    $zip->close();

    // The uncompressed size sits at byte 22 of the local file header and byte
    // 24 of the central directory record. Every other field, the CRC32
    // included, still describes the real payload.
    $raw = (string) file_get_contents($path);
    $local = (int) strpos($raw, "PK\x03\x04");
    $central = (int) strpos($raw, "PK\x01\x02");
    $raw = substr_replace($raw, pack('V', $declared), $local + 22, 4);
    file_put_contents($path, substr_replace($raw, pack('V', $declared), $central + 24, 4));

    return $path;
}

function lyingArchiveBytesUnder(string $directory): int
{
    $total = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        $total += (int) filesize((string) $file);
    }

    return $total;
}

function lyingArchiveExtractionDirectory(): string
{
    $directory = sys_get_temp_dir().'/lying-archive-out-'.uniqid('', true);
    mkdir($directory, 0o700, true);

    return $directory;
}

it('holds an entry to the size the zip-bomb cap was computed from', function (ArchiveReader $reader): void {
    $path = lyingArchiveWithDeclaredSize(1);
    $out = lyingArchiveExtractionDirectory();

    $reader->open($path);

    try {
        expect(fn (): bool => $reader->extractTo($out))
            ->toThrow(UnrecognizedMigrationFileException::class, 'expands past the 1 bytes its header declares');

        // The number that matters is not the exception: it is what is on the
        // disk when it is raised. An entry the cap counted as one byte must
        // not have put two megabytes there before anybody objected.
        expect(lyingArchiveBytesUnder($out))->toBeLessThan(
            LYING_ARCHIVE_PAYLOAD_BYTES,
            'The extraction wrote the whole payload before noticing it was not the size the index declared.',
        );
    } finally {
        $reader->close();
        @unlink($path);
        array_map('unlink', (array) glob($out.'/*'));
        @rmdir($out);
    }
})->with([
    'built-in reader' => fn (): ArchiveReader => new NativeZipReader,
    'ext-zip reader' => fn (): ArchiveReader => new ZipArchiveReader,
]);

// Without this the refusal above would read the same whether the guard works
// or the reader simply cannot extract a deflated entry at all.
it('extracts the same archive when its header tells the truth', function (ArchiveReader $reader): void {
    $path = lyingArchiveWithDeclaredSize(LYING_ARCHIVE_PAYLOAD_BYTES);
    $out = lyingArchiveExtractionDirectory();

    $reader->open($path);

    try {
        expect($reader->extractTo($out))->toBeTrue();
        expect(lyingArchiveBytesUnder($out))->toBe(LYING_ARCHIVE_PAYLOAD_BYTES);
    } finally {
        $reader->close();
        @unlink($path);
        array_map('unlink', (array) glob($out.'/*'));
        @rmdir($out);
    }
})->with([
    'built-in reader' => fn (): ArchiveReader => new NativeZipReader,
    'ext-zip reader' => fn (): ArchiveReader => new ZipArchiveReader,
]);

// The whole point of the cap is the path a reader actually walks, and on that
// path the refusal has to be the one the screen already knows how to say.
it('refuses the lying archive through ZipExtractor on both backends', function (bool $extension): void {
    $path = lyingArchiveWithDeclaredSize(1);
    $extractor = new ZipExtractor(readers: new ArchiveReaderFactory(zipExtensionAvailable: $extension));

    try {
        expect(fn (): string => $extractor->extract($path))->toThrow(UnrecognizedMigrationFileException::class);
    } finally {
        $extractor->cleanup();
        @unlink($path);
    }
})->with([
    'built-in reader' => [false],
    'ext-zip reader' => [true],
]);

// A truncated download reaches the extension reader as a stream that simply
// stops, with no error on it: 720 bytes came back off an edited payload and
// fread never said a word. extractTo() used to check the CRC32 itself.
it('refuses an entry whose bytes are not its own, through the ext-zip reader', function (): void {
    $path = sys_get_temp_dir().'/lying-archive-crc-'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('Register.csv', str_repeat("date,payee,amount\n", 40));
    $zip->setCompressionName('Register.csv', ZipArchive::CM_STORE);
    $zip->close();

    $raw = (string) file_get_contents($path);
    file_put_contents($path, substr_replace($raw, 'X', 30 + strlen('Register.csv') + 4, 1));

    $reader = new ZipArchiveReader;
    $out = lyingArchiveExtractionDirectory();
    $reader->open($path);

    try {
        expect(fn (): bool => $reader->extractTo($out))
            ->toThrow(UnrecognizedMigrationFileException::class, 'fails its own CRC32 check');
    } finally {
        $reader->close();
        @unlink($path);
        array_map('unlink', (array) glob($out.'/*'));
        @rmdir($out);
    }
});

// The other direction of the same lie, and the one a truncated download
// produces by accident: the index promises more than the payload holds.
it('refuses an entry that stops short of the size its header declares', function (ArchiveReader $reader): void {
    $declared = LYING_ARCHIVE_PAYLOAD_BYTES * 2;
    $path = lyingArchiveWithDeclaredSize($declared);
    $out = lyingArchiveExtractionDirectory();

    $reader->open($path);

    try {
        expect(fn (): bool => $reader->extractTo($out))->toThrow(
            UnrecognizedMigrationFileException::class,
            sprintf('expanded to %d bytes where its header declares %d', LYING_ARCHIVE_PAYLOAD_BYTES, $declared),
        );
    } finally {
        $reader->close();
        @unlink($path);
        array_map('unlink', (array) glob($out.'/*'));
        @rmdir($out);
    }
})->with([
    'built-in reader' => fn (): ArchiveReader => new NativeZipReader,
    'ext-zip reader' => fn (): ArchiveReader => new ZipArchiveReader,
]);

// An entry the extension will not hand a stream for. The built-in reader names
// the capability instead, which is the sentence a phone needs; on the desktop
// it is the archive that cannot be read, and the screen says so.
it('refuses an encrypted entry through the ext-zip reader rather than writing an empty file', function (): void {
    $path = sys_get_temp_dir().'/lying-archive-encrypted-'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('Register.csv', str_repeat('date,payee,amount', 20));
    $zip->setEncryptionName('Register.csv', ZipArchive::EM_AES_256, 'not the reader\'s to know');
    $zip->close();

    $extractor = new ZipExtractor(readers: new ArchiveReaderFactory(zipExtensionAvailable: true));

    try {
        expect(fn (): string => $extractor->extract($path))
            ->toThrow(UnrecognizedMigrationFileException::class, 'failed to extract archive contents');
    } finally {
        $extractor->cleanup();
        @unlink($path);
    }
});
