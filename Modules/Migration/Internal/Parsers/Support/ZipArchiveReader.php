<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use ZipArchive;

/**
 * @link ../../../../../.docs/features/migration/reading-a-zip-without-ext-zip.md
 */
final class ZipArchiveReader implements ArchiveReader
{
    private const int UNIX_MODE_FMT_MASK = 0o170000;

    private const int UNIX_MODE_SYMLINK = 0o120000;

    private ?ZipArchive $zip = null;

    public function open(string $path): void
    {
        $zip = new ZipArchive;
        $opened = $zip->open($path);
        if ($opened !== true) {
            throw new UnrecognizedMigrationFileException(
                "could not open zip archive at '{$path}' (code {$opened})",
            );
        }

        $this->zip = $zip;
    }

    public function entryCount(): int
    {
        return $this->opened()->numFiles;
    }

    /**
     * @return list<ArchiveEntry>
     */
    public function index(): array
    {
        $zip = $this->opened();

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new UnrecognizedMigrationFileException("could not read zip entry metadata at index {$i}");
            }

            $entries[] = new ArchiveEntry(
                $stat['name'],
                $stat['size'],
                $this->isSymlinkEntry($zip, $i),
            );
        }

        return $entries;
    }

    public function extractTo(string $directory): bool
    {
        return $this->opened()->extractTo($directory);
    }

    public function close(): void
    {
        $this->zip?->close();
        $this->zip = null;
    }

    private function opened(): ZipArchive
    {
        $zip = $this->zip;
        if ($zip === null) {
            throw new UnrecognizedMigrationFileException('the archive was asked about before it was opened');
        }

        return $zip;
    }

    private function isSymlinkEntry(ZipArchive $zip, int $index): bool
    {
        // Only OPSYS_UNIX entries carry a Unix mode in the upper 16 bits of the
        // external attributes, so on any other OS this is a no-op rather than a
        // false positive.
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
}
