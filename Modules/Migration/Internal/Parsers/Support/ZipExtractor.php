<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;
use RuntimeException;
use ZipArchive;

/**
 * Extracts an uploaded migration-source ZIP (YNAB4/nYNAB/Actual export) into
 * a scoped temp directory under `storage/app/` — never a web-served path
 * (T-13.5-06). Guards against both threats the STRIDE register flags for
 * this component:
 *
 *  - T-13.5-05 (zip-bomb, Denial of Service): every entry's uncompressed
 *    size is read via `ZipArchive::statIndex()` BEFORE any bytes are
 *    written to disk; the running total and the entry count are each capped,
 *    rejecting the archive the moment either cap is exceeded.
 *  - T-13.5-06 (zip-slip, Tampering): every entry name is checked for an
 *    absolute path or a `..` traversal segment before extraction; a single
 *    offending entry rejects the whole archive. Entries carrying a Unix
 *    symlink mode bit (WR-04) are rejected the same way — a symlink's name
 *    itself is not a "name" the traversal check inspects at all, but
 *    `ZipArchive::extractTo()` can materialize it as an actual filesystem
 *    symlink whose target string is attacker-controlled and unconstrained,
 *    which a caller's subsequent `is_file()`/`fopen()` on the extracted path
 *    would silently follow outside the scoped extraction directory.
 *
 * `$maxEntries`/`$maxTotalUncompressedBytes` are constructor-configurable
 * (sane production defaults below) so tests can exercise both caps
 * deterministically without constructing multi-hundred-entry or
 * multi-hundred-megabyte fixtures.
 *
 * Per `ParsesMigrationSource`'s own contract docblock, extraction happens
 * UPSTREAM of the parser seam — `Ynab4Parser`/`NynabParser`/`ActualParser`
 * all receive an already-extracted directory. This class is the Plan 05
 * upload-handling seam (`StartMigrationRun`) that produces that directory
 * from a raw uploaded ZIP; it is not invoked by the parsers themselves.
 */
final class ZipExtractor
{
    private const DEFAULT_MAX_ENTRIES = 500;

    private const DEFAULT_MAX_TOTAL_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;

    /** POSIX `S_IFMT` mask applied to a Unix external-attribute mode. */
    private const UNIX_MODE_FMT_MASK = 0o170000;

    /** POSIX `S_IFLNK` — a symlink entry. */
    private const UNIX_MODE_SYMLINK = 0o120000;

    private ?string $extractedDir = null;

    public function __construct(
        private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES,
        private readonly int $maxTotalUncompressedBytes = self::DEFAULT_MAX_TOTAL_UNCOMPRESSED_BYTES,
    ) {}

    /**
     * Extracts `$zipPath` into a fresh scoped directory and returns its
     * absolute path. Throws before writing a single byte if the archive
     * fails to open, exceeds either cap, or contains a path-traversal entry.
     */
    public function extract(string $zipPath): string
    {
        $zip = new ZipArchive;
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new UnrecognizedMigrationFileException("could not open zip archive at '{$zipPath}' (code {$opened})");
        }

        $entryCount = $zip->numFiles;
        if ($entryCount > $this->maxEntries) {
            $zip->close();

            throw new UnrecognizedMigrationFileException(sprintf(
                'archive contains %d entries, exceeding the allowed maximum of %d (zip-bomb guard, T-13.5-05)',
                $entryCount,
                $this->maxEntries,
            ));
        }

        $totalUncompressed = 0;
        for ($i = 0; $i < $entryCount; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();

                throw new UnrecognizedMigrationFileException("could not read zip entry metadata at index {$i}");
            }

            $totalUncompressed += $stat['size'];
            if ($totalUncompressed > $this->maxTotalUncompressedBytes) {
                $zip->close();

                throw new UnrecognizedMigrationFileException(sprintf(
                    'archive exceeds the maximum allowed total uncompressed size of %d bytes (zip-bomb guard, T-13.5-05)',
                    $this->maxTotalUncompressedBytes,
                ));
            }

            $name = $stat['name'];
            if ($this->escapesExtractionScope($name)) {
                $zip->close();

                throw new UnrecognizedMigrationFileException(
                    "archive entry '{$name}' resolves outside the extraction directory (zip-slip guard, T-13.5-06)",
                );
            }

            if ($this->isSymlinkEntry($zip, $i)) {
                $zip->close();

                throw new UnrecognizedMigrationFileException(
                    "archive entry '{$name}' is a symlink, which is not permitted (zip-slip guard, WR-04/T-13.5-06)",
                );
            }
        }

        $targetDir = UserDataPathService::appPath('migration-extracts/'.uniqid('run-', true));
        if (! mkdir($targetDir, 0700, true) && ! is_dir($targetDir)) {
            $zip->close();

            throw new RuntimeException("could not create scoped extraction directory '{$targetDir}'");
        }

        $extracted = $zip->extractTo($targetDir);
        $zip->close();

        if ($extracted === false) {
            throw new UnrecognizedMigrationFileException('failed to extract archive contents');
        }

        $this->extractedDir = $targetDir;

        return $targetDir;
    }

    /**
     * Removes the directory this instance last extracted into, if any.
     * Safe to call multiple times / when nothing was extracted.
     */
    public function cleanup(): void
    {
        if ($this->extractedDir === null || ! is_dir($this->extractedDir)) {
            $this->extractedDir = null;

            return;
        }

        $this->removeRecursively($this->extractedDir);
        $this->extractedDir = null;
    }

    /**
     * An entry "escapes" the scoped extraction directory when it is an
     * absolute path (Unix-rooted or Windows-drive-rooted) or contains a
     * `..` path-traversal segment anywhere in its normalised path.
     */
    private function escapesExtractionScope(string $entryName): bool
    {
        $normalised = str_replace('\\', '/', $entryName);
        if (str_starts_with($normalised, '/') || preg_match('#^[A-Za-z]:#', $normalised) === 1) {
            return true;
        }

        $segments = explode('/', $normalised);

        return in_array('..', $segments, true);
    }

    /**
     * True when this entry's Unix external file-attribute bits mark it as a
     * symlink (WR-04). Only `ZipArchive::OPSYS_UNIX`-tagged entries carry a
     * meaningful Unix mode in the upper 16 bits of the external attributes —
     * an archive built on another OS (e.g. Windows/FAT, `OPSYS_DOS`) never
     * sets this bit, so this check is a no-op (never a false positive) for
     * those archives.
     */
    private function isSymlinkEntry(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        $mode = (is_int($attr) ? $attr : 0) >> 16 & self::UNIX_MODE_FMT_MASK;

        return $mode === self::UNIX_MODE_SYMLINK;
    }

    private function removeRecursively(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->removeRecursively($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
