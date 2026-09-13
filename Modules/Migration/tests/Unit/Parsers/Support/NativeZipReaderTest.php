<?php

declare(strict_types=1);

use Modules\Migration\Internal\Exceptions\ArchiveReaderUnavailableException;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Internal\Parsers\Support\ArchiveEntry;
use Modules\Migration\Internal\Parsers\Support\ArchiveReaderFactory;
use Modules\Migration\Internal\Parsers\Support\NativeZipReader;
use Modules\Migration\Internal\Parsers\Support\ZipArchiveReader;
use Modules\Migration\Internal\Parsers\Support\ZipExtractor;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

/**
 * @param  array<string, string>  $entries  entryName => contents
 */
function nativeZipReaderBuildZip(array $entries, int $compression = ZipArchive::CM_DEFLATE): string
{
    $path = sys_get_temp_dir().'/native-zip-reader-'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
        $zip->setCompressionName($name, $compression);
    }
    $zip->close();

    return $path;
}

/**
 * @return array<string, string> relative path => contents, for every file under $dir
 */
function nativeZipReaderTreeOf(string $dir, string $prefix = ''): array
{
    $found = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.DIRECTORY_SEPARATOR.$entry;
        if (is_dir($path)) {
            $found = array_merge($found, nativeZipReaderTreeOf($path, $prefix.$entry.'/'));

            continue;
        }

        $found[$prefix.$entry] = (string) file_get_contents($path);
    }

    ksort($found);

    return $found;
}

function nativeZipReaderExtractorWithoutExtension(int ...$caps): ZipExtractor
{
    return new ZipExtractor(
        maxEntries: $caps[0] ?? 500,
        maxTotalUncompressedBytes: $caps[1] ?? 200 * 1024 * 1024,
        readers: new ArchiveReaderFactory(zipExtensionAvailable: false),
    );
}

it('NativeZipReader: reads the committed golden fixtures byte-for-byte the way ext-zip does', function (string $fixture): void {
    $native = nativeZipReaderExtractorWithoutExtension();
    $extension = new ZipExtractor(readers: new ArchiveReaderFactory(zipExtensionAvailable: true));

    try {
        $nativeTree = nativeZipReaderTreeOf($native->extract($fixture));
        $extensionTree = nativeZipReaderTreeOf($extension->extract($fixture));

        expect($nativeTree)->not->toBe([]);
        expect($nativeTree)->toBe($extensionTree);
    } finally {
        $native->cleanup();
        $extension->cleanup();
    }
})->with([
    'nynab v1' => fn (): string => MigrationFixturePaths::nynabZip('v1'),
    'nynab v2' => fn (): string => MigrationFixturePaths::nynabZip('v2'),
    'corrupt' => fn (): string => MigrationFixturePaths::corruptZip(),
]);

it('NativeZipReader: indexes an archive exactly as the ext-zip reader does', function (): void {
    $path = nativeZipReaderBuildZip([
        'budget/Register.csv' => str_repeat("a,b,c\n", 200),
        'budget/Budget.csv' => 'one line',
        'empty.txt' => '',
    ]);

    $native = new NativeZipReader;
    $extension = new ZipArchiveReader;
    $native->open($path);
    $extension->open($path);

    $shape = static fn (ArchiveEntry $entry): array => [$entry->name, $entry->uncompressedSize, $entry->isSymlink];

    expect(array_map($shape, $native->index()))->toBe(array_map($shape, $extension->index()));
    expect($native->entryCount())->toBe($extension->entryCount());

    $native->close();
    $extension->close();
    @unlink($path);
});

it('NativeZipReader: preserves nested directories and stored entries', function (): void {
    $path = nativeZipReaderBuildZip([
        'export/data/Register.csv' => "date,payee\n2026-01-01,Shop\n",
        'export/Budget.csv' => 'month,budgeted',
    ], ZipArchive::CM_STORE);

    $extractor = nativeZipReaderExtractorWithoutExtension();

    try {
        expect(nativeZipReaderTreeOf($extractor->extract($path)))->toBe([
            'export/Budget.csv' => 'month,budgeted',
            'export/data/Register.csv' => "date,payee\n2026-01-01,Shop\n",
        ]);
    } finally {
        $extractor->cleanup();
        @unlink($path);
    }
});

it('NativeZipReader: keeps every ZipExtractor guard when ext-zip is absent', function (string $case, callable $build, int $maxEntries, int $maxBytes): void {
    $path = $build();
    $extractor = nativeZipReaderExtractorWithoutExtension($maxEntries, $maxBytes);

    expect(fn (): string => $extractor->extract($path))
        ->toThrow(UnrecognizedMigrationFileException::class, '', sprintf('The %s guard did not fire on the built-in reader.', $case));

    $extractor->cleanup();
    @unlink($path);
})->with([
    'entry-count cap' => ['entry-count', fn (): string => nativeZipReaderBuildZip(['a' => 'a', 'b' => 'b', 'c' => 'c']), 2, 200 * 1024 * 1024],
    'uncompressed-size cap' => ['uncompressed-size', fn (): string => nativeZipReaderBuildZip(['big.txt' => str_repeat('x', 1000)]), 500, 10],
    'zip-slip traversal' => ['zip-slip', fn (): string => nativeZipReaderBuildZip(['../../etc/evil.txt' => 'malicious']), 500, 200 * 1024 * 1024],
    'zip-slip absolute path' => ['absolute-path', fn (): string => nativeZipReaderBuildZip(['/etc/evil.txt' => 'malicious']), 500, 200 * 1024 * 1024],
]);

it('NativeZipReader: rejects a symlink entry the same way ext-zip does', function (): void {
    $path = sys_get_temp_dir().'/native-zip-reader-symlink-'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('evil-link', '/etc/passwd');
    $zip->setExternalAttributesName('evil-link', ZipArchive::OPSYS_UNIX, 0o120777 << 16);
    $zip->close();

    $extractor = nativeZipReaderExtractorWithoutExtension();

    expect(fn (): string => $extractor->extract($path))->toThrow(UnrecognizedMigrationFileException::class);

    $extractor->cleanup();
    @unlink($path);
});

it('NativeZipReader: calls a file that is not an archive unreadable rather than raising an Error', function (): void {
    $path = sys_get_temp_dir().'/native-zip-reader-garbage-'.uniqid('', true).'.zip';
    file_put_contents($path, str_repeat('not an archive at all ', 20));

    $reader = new NativeZipReader;

    expect(fn () => $reader->open($path))->toThrow(UnrecognizedMigrationFileException::class);

    @unlink($path);
});

it('NativeZipReader: names the missing capability when an entry uses a compression it cannot inflate', function (): void {
    $path = nativeZipReaderBuildZip(['Register.csv' => str_repeat('date,payee,amount', 40)], ZipArchive::CM_STORE);

    $raw = (string) file_get_contents($path);
    $local = (int) strpos($raw, "PK\x03\x04");
    $central = (int) strpos($raw, "PK\x01\x02");
    $raw = substr_replace($raw, pack('v', 12), $local + 8, 2);
    file_put_contents($path, substr_replace($raw, pack('v', 12), $central + 10, 2));

    $reader = new NativeZipReader;

    expect(fn () => $reader->open($path))->toThrow(ArchiveReaderUnavailableException::class);

    @unlink($path);
});

it('NativeZipReader: refuses an entry whose bytes do not match its own checksum', function (): void {
    $path = nativeZipReaderBuildZip(['Register.csv' => str_repeat("date,payee,amount\n", 40)], ZipArchive::CM_STORE);

    // The payload begins after the 30-byte local header plus the entry name, so
    // flipping a byte there leaves every header intact and only the content
    // wrong -- a truncated download, not a file of the wrong kind.
    $raw = (string) file_get_contents($path);
    $payload = 30 + strlen('Register.csv');
    file_put_contents($path, substr_replace($raw, 'X', $payload + 4, 1));

    $extractor = nativeZipReaderExtractorWithoutExtension();

    expect(fn (): string => $extractor->extract($path))->toThrow(UnrecognizedMigrationFileException::class);

    $extractor->cleanup();
    @unlink($path);
});

// The three refusals below name the archive they refused, and each message is
// assembled rather than fixed. A reader that answers the right exception with
// the wrong subject sends whoever is holding a broken export to the wrong file.
it('NativeZipReader: names the archive it could not open at all', function (): void {
    $missing = sys_get_temp_dir().'/native-zip-reader-absent-'.uniqid('', true).'.zip';

    $reader = new NativeZipReader;

    expect(fn () => $reader->open($missing))
        ->toThrow(UnrecognizedMigrationFileException::class, "could not open zip archive at '".$missing."'");
});

it('NativeZipReader: refuses a file too short to carry an end record, and says so', function (): void {
    $path = sys_get_temp_dir().'/native-zip-reader-stub-'.uniqid('', true).'.zip';
    file_put_contents($path, "PK\x05\x06");

    $reader = new NativeZipReader;

    expect(fn () => $reader->open($path))
        ->toThrow(UnrecognizedMigrationFileException::class, "'".$path."' is too short to be a zip archive");

    @unlink($path);
});

it('NativeZipReader: refuses to answer about an archive nobody opened', function (): void {
    $reader = new NativeZipReader;

    expect(fn () => $reader->entryCount())
        ->toThrow(UnrecognizedMigrationFileException::class, 'the archive was asked about before it was opened');

    // close() is the one question an unopened reader may be asked, because a
    // caller unwinding from a failed open has no way to know which it is.
    $reader->close();

    expect(fn () => $reader->index())
        ->toThrow(UnrecognizedMigrationFileException::class, 'the archive was asked about before it was opened');
});

/**
 * @param  array<int, array{0: int, 1: string}>  $patches  offset from the record's start => replacement bytes
 */
function nativeZipReaderPatch(string $path, string $signature, array $patches): string
{
    $raw = (string) file_get_contents($path);
    $record = $signature === "PK\x05\x06" ? (int) strrpos($raw, $signature) : (int) strpos($raw, $signature);

    foreach ($patches as [$offset, $bytes]) {
        $raw = substr_replace($raw, $bytes, $record + $offset, strlen($bytes));
    }

    file_put_contents($path, $raw);

    return $path;
}

// Every refusal below is a hand-edited archive rather than a fixture, because
// the shapes being refused are ones no packer writes. The subject is what the
// reader says about each: a file it cannot read and a file this build has no
// reader for are answered by different sentences on the screen.
it('NativeZipReader: names what it refuses in a malformed archive', function (callable $build, string $exception, string $message): void {
    $path = $build();
    $reader = new NativeZipReader;

    expect(fn () => $reader->open($path))->toThrow($exception, $message);

    $reader->close();
    @unlink($path);
})->with([
    'a ZIP64 archive' => [
        fn (): string => nativeZipReaderPatch(
            nativeZipReaderBuildZip(['Register.csv' => 'date,payee']),
            "PK\x05\x06",
            [[16, pack('V', 0xFFFFFFFF)]],
        ),
        ArchiveReaderUnavailableException::class,
        'is a ZIP64 archive, which the built-in reader cannot open',
    ],
    'an encrypted entry' => [
        function (): string {
            $path = sys_get_temp_dir().'/native-zip-reader-encrypted-'.uniqid('', true).'.zip';
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE);
            $zip->addFromString('Register.csv', str_repeat('date,payee,amount', 20));
            $zip->setEncryptionName('Register.csv', ZipArchive::EM_AES_256, 'not the reader\'s to know');
            $zip->close();

            return $path;
        },
        ArchiveReaderUnavailableException::class,
        "archive entry 'Register.csv' is encrypted, which the built-in reader cannot open",
    ],
    'an end record that runs off the end of the file' => [
        function (): string {
            $path = sys_get_temp_dir().'/native-zip-reader-stub-eocd-'.uniqid('', true).'.zip';
            file_put_contents($path, str_repeat('x', 25)."PK\x05\x06");

            return $path;
        },
        UnrecognizedMigrationFileException::class,
        'ends inside its own end-of-central-directory record',
    ],
    'a central directory shorter than the end record declares' => [
        function (): string {
            $path = nativeZipReaderBuildZip(['Register.csv' => str_repeat("date,payee,amount\n", 500)]);
            $raw = (string) file_get_contents($path);
            $central = (int) strpos($raw, "PK\x01\x02");
            file_put_contents($path, substr($raw, 0, (int) floor($central * 0.4)).substr($raw, $central));

            return $path;
        },
        UnrecognizedMigrationFileException::class,
        'is shorter than its own end record declares',
    ],
    'an end record counting more entries than the directory holds' => [
        fn (): string => nativeZipReaderPatch(
            nativeZipReaderBuildZip(['Register.csv' => 'date,payee']),
            "PK\x05\x06",
            [[10, pack('v', 2)]],
        ),
        UnrecognizedMigrationFileException::class,
        'could not read zip entry metadata at index 1',
    ],
    'an entry name longer than the directory that carries it' => [
        fn (): string => nativeZipReaderPatch(
            nativeZipReaderBuildZip(['Register.csv' => 'date,payee']),
            "PK\x01\x02",
            [[28, pack('v', 0xFFFF)]],
        ),
        UnrecognizedMigrationFileException::class,
        'zip entry name at index 0 runs past the end of the central directory',
    ],
]);

// These two are refused while an entry is being written rather than while the
// directory is being read, so they are the endings ZipExtractor has to be able
// to clean up after.
it('NativeZipReader: names what it refuses while writing an entry', function (callable $build, string $message): void {
    $path = $build();
    $extractor = nativeZipReaderExtractorWithoutExtension();

    expect(fn (): string => $extractor->extract($path))->toThrow(UnrecognizedMigrationFileException::class, $message);

    $extractor->cleanup();
    @unlink($path);
})->with([
    'an entry pointing at no local file header' => [
        fn (): string => nativeZipReaderPatch(
            nativeZipReaderBuildZip(['Register.csv' => 'date,payee']),
            "PK\x01\x02",
            [[42, pack('V', 1)]],
        ),
        "archive entry 'Register.csv' points at no local file header",
    ],
    'an entry declaring more compressed bytes than the file holds' => [
        fn (): string => nativeZipReaderPatch(
            nativeZipReaderBuildZip(['Register.csv' => 'date,payee']),
            "PK\x01\x02",
            [[20, pack('V', 4_000_000)]],
        ),
        "archive entry 'Register.csv' stops before the length its header declares",
    ],
]);

it('NativeZipReader: reads an archive with no entries in it at all', function (): void {
    $path = sys_get_temp_dir().'/native-zip-reader-empty-'.uniqid('', true).'.zip';
    file_put_contents($path, "PK\x05\x06".pack('vvvvVVv', 0, 0, 0, 0, 0, 0, 0));

    $extractor = nativeZipReaderExtractorWithoutExtension();

    try {
        expect(nativeZipReaderTreeOf($extractor->extract($path)))->toBe([]);
    } finally {
        $extractor->cleanup();
        @unlink($path);
    }
});

it('NativeZipReader: makes the directory an explicit directory entry names', function (): void {
    $path = sys_get_temp_dir().'/native-zip-reader-dir-'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addEmptyDir('export/data');
    $zip->addFromString('export/data/Register.csv', 'date,payee');
    $zip->close();

    $extractor = nativeZipReaderExtractorWithoutExtension();

    try {
        $extracted = $extractor->extract($path);
        expect(is_dir($extracted.'/export/data'))->toBeTrue();
        expect(nativeZipReaderTreeOf($extracted))->toBe(['export/data/Register.csv' => 'date,payee']);
    } finally {
        $extractor->cleanup();
        @unlink($path);
    }
});

// Refusing this is the same answer ext-zip gives, and it is the branch that
// decides whether an entry with nowhere to go is a failed extraction or a
// silently missing file.
it('NativeZipReader: refuses an archive whose entry has a file where its parent directory should be', function (): void {
    $path = nativeZipReaderBuildZip([
        'export' => 'this is a file, not a directory',
        'export/Register.csv' => 'date,payee',
    ]);

    $extractor = nativeZipReaderExtractorWithoutExtension();

    expect(fn (): string => $extractor->extract($path))
        ->toThrow(UnrecognizedMigrationFileException::class, 'failed to extract archive contents');

    $extractor->cleanup();
    @unlink($path);
});

// A DOS packer puts an MS-DOS date where a Unix one puts a mode, so reading
// permission bits out of it would call ordinary exports symlinks.
it('NativeZipReader: reads a symlink mode only from a Unix packer', function (int $opsys, bool $symlink): void {
    $path = sys_get_temp_dir().'/native-zip-reader-opsys-'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('maybe-link', '/etc/passwd');
    $zip->setExternalAttributesName('maybe-link', $opsys, 0o120777 << 16);
    $zip->close();

    $reader = new NativeZipReader;
    $reader->open($path);

    try {
        expect($reader->index()[0]->isSymlink)->toBe($symlink);
    } finally {
        $reader->close();
        @unlink($path);
    }
})->with([
    'unix' => [ZipArchive::OPSYS_UNIX, true],
    'dos' => [ZipArchive::OPSYS_DOS, false],
]);
