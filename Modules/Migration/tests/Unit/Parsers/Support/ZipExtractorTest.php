<?php

declare(strict_types=1);

use Modules\Migration\Internal\Parsers\Support\ZipExtractor;
use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;

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

it('ZipExtractor: rejects an entry-count cap violation before extracting a single byte (T-13.5-05)', function (): void {
    $zipPath = migrationBuildZip(['a.txt' => 'a', 'b.txt' => 'b', 'c.txt' => 'c']);
    $extractor = new ZipExtractor(maxEntries: 2);

    expect(fn () => $extractor->extract($zipPath))->toThrow(UnrecognizedMigrationFileException::class);

    @unlink($zipPath);
});

it('ZipExtractor: rejects a total-uncompressed-size cap violation (zip-bomb guard, T-13.5-05)', function (): void {
    $zipPath = migrationBuildZip(['big.txt' => str_repeat('x', 1000)]);
    $extractor = new ZipExtractor(maxTotalUncompressedBytes: 10);

    expect(fn () => $extractor->extract($zipPath))->toThrow(UnrecognizedMigrationFileException::class);

    @unlink($zipPath);
});

it('ZipExtractor: rejects a path-traversal entry (zip-slip guard, T-13.5-06)', function (): void {
    $zipPath = migrationBuildZip(['../../etc/evil.txt' => 'malicious']);
    $extractor = new ZipExtractor;

    expect(fn () => $extractor->extract($zipPath))->toThrow(UnrecognizedMigrationFileException::class);

    @unlink($zipPath);
});

it('ZipExtractor: rejects an absolute-path entry (zip-slip guard, T-13.5-06)', function (): void {
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

it('ZipExtractor: rejects an archive containing a symlink entry (WR-04/T-13.5-06)', function (): void {
    // A malicious export ZIP could plant a symlink named e.g. "db.sqlite"
    // whose target string is attacker-controlled and unconstrained by the
    // name-based zip-slip check above — ZipArchive::extractTo() can
    // materialize it as an ACTUAL filesystem symlink, which a caller's
    // later is_file()/fopen() on the extracted path would silently follow.
    $zipPath = sys_get_temp_dir().'/zip-extractor-symlink-test-'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('evil-link', '/etc/passwd');
    // Unix external attributes: upper 16 bits carry the S_IFLNK (0120000)
    // file-type bits + permission bits, mirroring what a real symlink entry
    // (e.g. produced by `zip --symlinks`) carries.
    $zip->setExternalAttributesName('evil-link', ZipArchive::OPSYS_UNIX, 0o120777 << 16);
    $zip->close();

    $extractor = new ZipExtractor;

    expect(fn () => $extractor->extract($zipPath))->toThrow(UnrecognizedMigrationFileException::class);

    @unlink($zipPath);
});
