<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Migration\Internal\Exceptions\ExtractionDirectoryException;
use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;
use ZipArchive;

/**
 * @link ../../../../../.docs/features/migration/architecture.md
 */
final class ZipExtractor
{
    private const DEFAULT_MAX_ENTRIES = 500;

    private const DEFAULT_MAX_TOTAL_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;

    private const UNIX_MODE_FMT_MASK = 0o170000;

    private const UNIX_MODE_SYMLINK = 0o120000;

    private ?string $extractedDir = null;

    public function __construct(
        private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES,
        private readonly int $maxTotalUncompressedBytes = self::DEFAULT_MAX_TOTAL_UNCOMPRESSED_BYTES,
    ) {}

    public function extract(string $zipPath): string
    {
        // Throws before writing a single byte if the archive fails to open,
        // exceeds either cap, or contains a path-traversal/symlink entry.
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
        // Suppressed so the is_dir() race check decides. Unsuppressed, a
        // concurrent creator's EEXIST warning becomes an ErrorException and
        // the race the second clause exists to absorb becomes a failure.
        if (! @mkdir($targetDir, 0700, true) && ! is_dir($targetDir)) {
            $zip->close();

            throw new ExtractionDirectoryException($targetDir);
        }

        // Tracked BEFORE extractTo() runs — if extraction fails partway,
        // cleanup() must still be able to find and remove whatever was
        // partially written, rather than leaking $targetDir permanently.
        $this->extractedDir = $targetDir;

        $extracted = $zip->extractTo($targetDir);
        $zip->close();

        if ($extracted === false) {
            throw new UnrecognizedMigrationFileException('failed to extract archive contents');
        }

        return $targetDir;
    }

    public function cleanup(): void
    {
        if ($this->extractedDir === null || ! is_dir($this->extractedDir)) {
            $this->extractedDir = null;

            return;
        }

        $this->removeRecursively($this->extractedDir);
        $this->extractedDir = null;
    }

    private function escapesExtractionScope(string $entryName): bool
    {
        // An entry "escapes" the scoped directory when it is an absolute
        // path (Unix-rooted or Windows-drive-rooted) or contains a '..'
        // traversal segment anywhere in its normalised path.
        $normalised = str_replace('\\', '/', $entryName);
        if (str_starts_with($normalised, '/') || preg_match('#^[A-Za-z]:#', $normalised) === 1) {
            return true;
        }

        $segments = explode('/', $normalised);

        return in_array('..', $segments, true);
    }

    private function isSymlinkEntry(ZipArchive $zip, int $index): bool
    {
        // Only OPSYS_UNIX-tagged entries carry a meaningful Unix mode in the
        // upper 16 bits of the external attributes — an archive built on
        // another OS never sets this bit, so this check is a no-op (never a
        // false positive) for those archives.
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
