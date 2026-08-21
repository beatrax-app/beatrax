<?php

declare(strict_types=1);

use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Internal\Parsers\Support\ZipExtractor;

/**
 * @param  array<string, string>  $entries  entryName => contents
 */
function migrationBuildZip(array $entries): string
{
    $path = sys_get_temp_dir().'/zip-extractor-test-'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    return $path;
}

it('ZipExtractor: extracts a well-formed archive under storage/app, never a public path', function (): void {
    $zipPath = migrationBuildZip(['hello.txt' => 'hello world']);
    $extractor = new ZipExtractor;

    try {
        $dir = $extractor->extract($zipPath);

        expect(is_dir($dir))->toBeTrue();
        expect($dir)->toContain(DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app');
        expect($dir)->not->toContain(DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR);
        expect(is_file($dir.'/hello.txt'))->toBeTrue();
        expect(file_get_contents($dir.'/hello.txt'))->toBe('hello world');
    } finally {
        $extractor->cleanup();
        @unlink($zipPath);
    }
});

it('ZipExtractor: rejects an entry-count cap violation before extracting a single byte', function (): void {
    $zipPath = migrationBuildZip(['a.txt' => 'a', 'b.txt' => 'b', 'c.txt' => 'c']);
    $extractor = new ZipExtractor(maxEntries: 2);

    expect(fn () => $extractor->extract($zipPath))->toThrow(UnrecognizedMigrationFileException::class);

    @unlink($zipPath);
});

it('ZipExtractor: rejects a total-uncompressed-size cap violation (zip-bomb guard)', function (): void {
    $zipPath = migrationBuildZip(['big.txt' => str_repeat('x', 1000)]);
    $extractor = new ZipExtractor(maxTotalUncompressedBytes: 10);

    expect(fn () => $extractor->extract($zipPath))->toThrow(UnrecognizedMigrationFileException::class);

    @unlink($zipPath);
});

it('ZipExtractor: rejects a path-traversal entry (zip-slip guard)', function (): void {
    $zipPath = migrationBuildZip(['../../etc/evil.txt' => 'malicious']);
    $extractor = new ZipExtractor;

    expect(fn () => $extractor->extract($zipPath))->toThrow(UnrecognizedMigrationFileException::class);

    @unlink($zipPath);
});

it('ZipExtractor: rejects an absolute-path entry (zip-slip guard)', function (): void {
    $zipPath = migrationBuildZip(['/etc/evil.txt' => 'malicious']);
    $extractor = new ZipExtractor;

    expect(fn () => $extractor->extract($zipPath))->toThrow(UnrecognizedMigrationFileException::class);

    @unlink($zipPath);
});

it('ZipExtractor: throws when the archive cannot be opened at all', function (): void {
    $extractor = new ZipExtractor;

    expect(fn () => $extractor->extract(sys_get_temp_dir().'/does-not-exist-'.uniqid('', true).'.zip'))
        ->toThrow(UnrecognizedMigrationFileException::class);
});

it('ZipExtractor: cleanup() removes the partially-extracted directory when extractTo() fails partway', function (): void {
    // extractTo() cannot create "foo/bar.txt" once "foo" exists as a plain file,
    // which reproduces a mid-extraction failure without a disk-full or a
    // permission error to simulate.
    $zipPath = migrationBuildZip(['foo' => 'file content', 'foo/bar.txt' => 'nested content']);
    $extractor = new ZipExtractor;

    $thrown = null;
    $partialDir = null;

    try {
        $extractor->extract($zipPath);
    } catch (Throwable $e) {
        $thrown = $e;
        // Read the tracked directory to prove it existed before cleanup().
        $ref = new ReflectionProperty(ZipExtractor::class, 'extractedDir');
        $partialDir = $ref->getValue($extractor);
    }

    expect($thrown)->toBeInstanceOf(Throwable::class);
    expect($partialDir)->not->toBeNull();
    expect(is_dir($partialDir))->toBeTrue();

    $extractor->cleanup();

    expect(is_dir($partialDir))->toBeFalse();

    @unlink($zipPath);
});

it('ZipExtractor: rejects an archive containing a symlink entry', function (): void {
    // A malicious export could plant a symlink named e.g. "db.sqlite" whose
    // target the name-based zip-slip check cannot see; extractTo() would
    // materialize a real symlink that a later is_file()/fopen() follows.
    $zipPath = sys_get_temp_dir().'/zip-extractor-symlink-test-'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('evil-link', '/etc/passwd');
    // The upper 16 bits of the Unix external attributes carry S_IFLNK (0120000)
    // plus permissions, exactly as `zip --symlinks` writes them.
    $zip->setExternalAttributesName('evil-link', ZipArchive::OPSYS_UNIX, 0o120777 << 16);
    $zip->close();

    $extractor = new ZipExtractor;

    expect(fn () => $extractor->extract($zipPath))->toThrow(UnrecognizedMigrationFileException::class);

    @unlink($zipPath);
});
