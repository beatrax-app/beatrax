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
        ->toThrow(UnrecognizedMigrationFileException::class, '', "The {$case} guard did not fire on the built-in reader.");

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
