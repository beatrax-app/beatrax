<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Core\Public\Support\ZipLocalEntry;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use ZipArchive;

/**
 * @phpstan-type EntryStat array{name: string, size: int, crc: int}
 *
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
                sprintf("could not open zip archive at '%s' (code %s)", $path, $opened),
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
            $stat = $this->stat($zip, $i);

            $entries[] = new ArchiveEntry(
                $stat['name'],
                $stat['size'],
                $this->isSymlinkEntry($zip, $i),
            );
        }

        return $entries;
    }

    // Entry by entry through a stream rather than ZipArchive::extractTo(). The
    // extension holds an entry to nothing it declared: handed one announcing a
    // single byte and carrying ten megabytes, extractTo() wrote all ten and
    // returned true, under a cap that had counted the declared 1.
    public function extractTo(string $directory): bool
    {
        $zip = $this->opened();

        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (! $this->writeEntry($zip, $i, $this->stat($zip, $i), $directory)) {
                return false;
            }
        }

        return true;
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

    /**
     * @return EntryStat
     */
    private function stat(ZipArchive $zip, int $index): array
    {
        $stat = $zip->statIndex($index);
        if ($stat === false) {
            throw new UnrecognizedMigrationFileException(sprintf('could not read zip entry metadata at index %s', $index));
        }

        return $stat;
    }

    /**
     * @param  EntryStat  $stat
     */
    private function writeEntry(ZipArchive $zip, int $index, array $stat, string $directory): bool
    {
        if (str_ends_with($stat['name'], '/')) {
            return ExtractionTarget::directory($directory, $stat['name']);
        }

        // Ahead of the target file, so an entry the extension will not hand a
        // stream for leaves nothing on disk to be cleaned up after.
        $in = $zip->getStreamIndex($index);
        if ($in === false) {
            return false;
        }

        try {
            return $this->streamEntryInto($in, $stat, $directory);
        } finally {
            fclose($in);
        }
    }

    /**
     * @param  resource  $in
     * @param  EntryStat  $stat
     */
    private function streamEntryInto($in, array $stat, string $directory): bool
    {
        $out = ExtractionTarget::open($directory, $stat['name']);
        if ($out === false) {
            return false;
        }

        try {
            return $this->copyVerified($in, $out, $stat);
        } finally {
            fclose($out);
        }
    }

    // The CRC32 the extension checked for free inside extractTo() is checked
    // here instead: a stream hands back a truncated download's bytes without a
    // word — 720 of them, measured, off an entry whose payload was edited.
    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  EntryStat  $stat
     */
    private function copyVerified($in, $out, array $stat): bool
    {
        $arriving = new EntryAsDeclared($stat['name'], $stat['size'], $stat['crc']);

        while (true) {
            // Suppressed because libzip raises its CRC complaint as a PHP
            // warning on the read past the end, and an ErrorException off a
            // corrupt export is the screen blaming us for their file. sealed()
            // below says the same thing in the exception this reader promises.
            $chunk = @fread($in, max(1, min(ZipLocalEntry::READ_CHUNK_BYTES, $arriving->outstanding() + 1)));
            if ($chunk === false || $chunk === '') {
                break;
            }

            $arriving->accept($chunk);
            if (fwrite($out, $chunk) === false) {
                return false;
            }
        }

        $arriving->sealed();

        return true;
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
