<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Migration\Internal\Exceptions\ExtractionDirectoryException;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;

/**
 * @link ../../../../../.docs/features/migration/reading-a-zip-without-ext-zip.md
 */
final class ZipExtractor
{
    private const int DEFAULT_MAX_ENTRIES = 500;

    private const DEFAULT_MAX_TOTAL_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;

    private ?string $extractedDir = null;

    public function __construct(
        private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES,
        private readonly int $maxTotalUncompressedBytes = self::DEFAULT_MAX_TOTAL_UNCOMPRESSED_BYTES,
        private readonly ArchiveReaderFactory $readers = new ArchiveReaderFactory,
    ) {}

    public function extract(string $zipPath): string
    {
        // Throws before writing a single byte if the archive fails to open,
        // exceeds either cap, or contains a path-traversal/symlink entry.
        $reader = $this->readers->make();
        $reader->open($zipPath);

        try {
            $this->guardArchiveShape($reader);

            $targetDir = UserDataPathService::appPath('migration-extracts/'.uniqid('run-', true));
            // Suppressed so the is_dir() clause decides: unsuppressed, a concurrent
            // creator's EEXIST becomes an ErrorException instead of an absorbed race.
            if (! @mkdir($targetDir, 0700, true) && ! is_dir($targetDir)) {
                throw new ExtractionDirectoryException($targetDir);
            }

            // Tracked before extractTo(), so a partway failure still leaves cleanup()
            // able to find what was written rather than leaking $targetDir.
            $this->extractedDir = $targetDir;

            $extracted = $reader->extractTo($targetDir);
        } finally {
            $reader->close();
        }

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

    private function guardArchiveShape(ArchiveReader $reader): void
    {
        $entryCount = $reader->entryCount();
        if ($entryCount > $this->maxEntries) {
            throw new UnrecognizedMigrationFileException(sprintf(
                'archive contains %d entries, exceeding the allowed maximum of %d (zip-bomb guard)',
                $entryCount,
                $this->maxEntries,
            ));
        }

        $totalUncompressed = 0;
        foreach ($reader->index() as $entry) {
            $totalUncompressed += $entry->uncompressedSize;
            if ($totalUncompressed > $this->maxTotalUncompressedBytes) {
                throw new UnrecognizedMigrationFileException(sprintf(
                    'archive exceeds the maximum allowed total uncompressed size of %d bytes (zip-bomb guard)',
                    $this->maxTotalUncompressedBytes,
                ));
            }

            if ($this->escapesExtractionScope($entry->name)) {
                throw new UnrecognizedMigrationFileException(
                    "archive entry '{$entry->name}' resolves outside the extraction directory (zip-slip guard)",
                );
            }

            if ($entry->isSymlink) {
                throw new UnrecognizedMigrationFileException(
                    "archive entry '{$entry->name}' is a symlink, which is not permitted (zip-slip guard)",
                );
            }
        }
    }

    private function escapesExtractionScope(string $entryName): bool
    {
        $normalised = str_replace('\\', '/', $entryName);
        if (str_starts_with($normalised, '/') || preg_match('#^[A-Za-z]:#', $normalised) === 1) {
            return true;
        }

        $segments = explode('/', $normalised);

        return in_array('..', $segments, true);
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
