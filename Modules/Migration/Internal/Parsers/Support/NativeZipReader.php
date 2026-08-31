<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Migration\Internal\Exceptions\ArchiveReaderUnavailableException;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Throwable;

/**
 * @phpstan-type CentralEntry array{
 *     name: string,
 *     method: int,
 *     flags: int,
 *     crc32: int,
 *     compressedSize: int,
 *     uncompressedSize: int,
 *     localHeaderOffset: int,
 *     isSymlink: bool,
 * }
 *
 * @link ../../../../../.docs/features/migration/reading-a-zip-without-ext-zip.md
 */
final class NativeZipReader implements ArchiveReader
{
    private const string EOCD_SIGNATURE = "PK\x05\x06";

    private const int EOCD_FIXED_BYTES = 22;

    private const int MAX_TRAILING_COMMENT_BYTES = 0xFFFF;

    private const string CENTRAL_SIGNATURE = "PK\x01\x02";

    private const int CENTRAL_FIXED_BYTES = 46;

    private const string LOCAL_SIGNATURE = "PK\x03\x04";

    private const int LOCAL_FIXED_BYTES = 30;

    private const int METHOD_STORE = 0;

    private const int METHOD_DEFLATE = 8;

    private const int FLAG_ENCRYPTED = 0x0001;

    private const int ZIP64_SENTINEL_SHORT = 0xFFFF;

    private const int ZIP64_SENTINEL_LONG = 0xFFFFFFFF;

    private const int OPSYS_UNIX = 3;

    private const int UNIX_MODE_FMT_MASK = 0o170000;

    private const int UNIX_MODE_SYMLINK = 0o120000;

    private const int READ_CHUNK_BYTES = 262144;

    private const int DIRECTORY_MODE = 0o700;

    /** @var resource|null */
    private $handle = null;

    /** @var list<CentralEntry> */
    private array $entries = [];

    public function open(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new UnrecognizedMigrationFileException("could not open zip archive at '{$path}'");
        }

        $this->handle = $handle;

        try {
            $this->entries = $this->readCentralDirectory($path);
        } catch (Throwable $e) {
            $this->close();

            throw $e;
        }
    }

    public function entryCount(): int
    {
        $this->opened();

        return count($this->entries);
    }

    /**
     * @return list<ArchiveEntry>
     */
    public function index(): array
    {
        $this->opened();

        return array_map(
            static fn (array $entry): ArchiveEntry => new ArchiveEntry(
                $entry['name'],
                $entry['uncompressedSize'],
                $entry['isSymlink'],
            ),
            $this->entries,
        );
    }

    public function extractTo(string $directory): bool
    {
        $this->opened();

        return array_all($this->entries, fn (array $entry): bool => $this->writeEntry($entry, $directory));
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
        $this->entries = [];
    }

    /**
     * @return resource
     */
    private function opened()
    {
        if (! is_resource($this->handle)) {
            throw new UnrecognizedMigrationFileException('the archive was asked about before it was opened');
        }

        return $this->handle;
    }

    /**
     * @return list<CentralEntry>
     */
    private function readCentralDirectory(string $path): array
    {
        $eocd = $this->locateEndOfCentralDirectory($path);

        $raw = $this->readAt($eocd['offset'], $eocd['size']);
        if (strlen($raw) !== $eocd['size']) {
            throw new UnrecognizedMigrationFileException(
                "central directory of '{$path}' is shorter than its own end record declares",
            );
        }

        $entries = [];
        $cursor = 0;
        for ($i = 0; $i < $eocd['entries']; $i++) {
            [$entry, $cursor] = $this->readCentralEntry($raw, $cursor, $i);
            $this->guardEntryIsReadable($entry);
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @return array{offset: int, size: int, entries: int}
     */
    private function locateEndOfCentralDirectory(string $path): array
    {
        $fileSize = $this->fileSize($path);
        $window = min($fileSize, self::MAX_TRAILING_COMMENT_BYTES + self::EOCD_FIXED_BYTES);
        $tail = $this->readAt($fileSize - $window, $window);

        $position = strrpos($tail, self::EOCD_SIGNATURE);
        if ($position === false) {
            throw new UnrecognizedMigrationFileException(
                "'{$path}' carries no zip end-of-central-directory record",
            );
        }

        $record = substr($tail, $position, self::EOCD_FIXED_BYTES);
        if (strlen($record) < self::EOCD_FIXED_BYTES) {
            throw new UnrecognizedMigrationFileException(
                "'{$path}' ends inside its own end-of-central-directory record",
            );
        }

        $entries = $this->readShort($record, 10);
        $size = $this->readLong($record, 12);
        $offset = $this->readLong($record, 16);

        // A count or an offset pinned at its sentinel means the real value lives
        // in a ZIP64 record. Those archives run past both of ZipExtractor's caps
        // by construction, so this reader refuses rather than half-reads one.
        if ($entries === self::ZIP64_SENTINEL_SHORT
            || $size === self::ZIP64_SENTINEL_LONG
            || $offset === self::ZIP64_SENTINEL_LONG) {
            throw new ArchiveReaderUnavailableException(
                "'{$path}' is a ZIP64 archive, which the built-in reader cannot open",
            );
        }

        return ['offset' => $offset, 'size' => $size, 'entries' => $entries];
    }

    /**
     * @return array{0: CentralEntry, 1: int}
     */
    private function readCentralEntry(string $raw, int $cursor, int $index): array
    {
        $header = substr($raw, $cursor, self::CENTRAL_FIXED_BYTES);
        if (strlen($header) < self::CENTRAL_FIXED_BYTES
            || ! str_starts_with($header, self::CENTRAL_SIGNATURE)) {
            throw new UnrecognizedMigrationFileException(
                "could not read zip entry metadata at index {$index}",
            );
        }

        $nameLength = $this->readShort($header, 28);
        $extraLength = $this->readShort($header, 30);
        $commentLength = $this->readShort($header, 32);
        $name = substr($raw, $cursor + self::CENTRAL_FIXED_BYTES, $nameLength);
        if (strlen($name) !== $nameLength) {
            throw new UnrecognizedMigrationFileException(
                "zip entry name at index {$index} runs past the end of the central directory",
            );
        }

        $externalAttributes = $this->readLong($header, 38);

        return [
            [
                'name' => $name,
                'method' => $this->readShort($header, 10),
                'flags' => $this->readShort($header, 8),
                'crc32' => $this->readLong($header, 16),
                'compressedSize' => $this->readLong($header, 20),
                'uncompressedSize' => $this->readLong($header, 24),
                'localHeaderOffset' => $this->readLong($header, 42),
                'isSymlink' => $this->isSymlink($this->readShort($header, 4), $externalAttributes),
            ],
            $cursor + self::CENTRAL_FIXED_BYTES + $nameLength + $extraLength + $commentLength,
        ];
    }

    /**
     * @param  CentralEntry  $entry
     */
    private function guardEntryIsReadable(array $entry): void
    {
        if (($entry['flags'] & self::FLAG_ENCRYPTED) !== 0) {
            throw new ArchiveReaderUnavailableException(sprintf(
                "archive entry '%s' is encrypted, which the built-in reader cannot open",
                $entry['name'],
            ));
        }

        if ($entry['method'] === self::METHOD_DEFLATE && ! function_exists('inflate_init')) {
            throw new ArchiveReaderUnavailableException(sprintf(
                "archive entry '%s' is deflated and this build carries neither ext-zip nor zlib",
                $entry['name'],
            ));
        }

        if (! in_array($entry['method'], [self::METHOD_STORE, self::METHOD_DEFLATE], true)) {
            throw new ArchiveReaderUnavailableException(sprintf(
                "archive entry '%s' uses compression method %d; the built-in reader reads only stored and deflated entries",
                $entry['name'],
                $entry['method'],
            ));
        }
    }

    // The version-made-by field's high byte is the packing host. Only a Unix
    // packer puts a mode in the external attributes, so every other host answers
    // false here rather than reading permission bits out of an MS-DOS date.
    private function isSymlink(int $versionMadeBy, int $externalAttributes): bool
    {
        if ($versionMadeBy >> 8 !== self::OPSYS_UNIX) {
            return false;
        }

        return ($externalAttributes >> 16 & self::UNIX_MODE_FMT_MASK) === self::UNIX_MODE_SYMLINK;
    }

    /**
     * @param  CentralEntry  $entry
     */
    private function writeEntry(array $entry, string $directory): bool
    {
        $target = $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['name']);

        if (str_ends_with($entry['name'], '/')) {
            return is_dir($target) || @mkdir($target, self::DIRECTORY_MODE, true);
        }

        // A parent that cannot be made and a file that cannot be opened are
        // the same answer: there is nowhere to put this entry.
        $parent = dirname($target);
        $out = is_dir($parent) || @mkdir($parent, self::DIRECTORY_MODE, true)
            ? @fopen($target, 'wb')
            : false;

        if ($out === false) {
            return false;
        }

        try {
            return $this->streamEntryInto($entry, $out);
        } finally {
            fclose($out);
        }
    }

    /**
     * @param  CentralEntry  $entry
     * @param  resource  $out
     */
    private function streamEntryInto(array $entry, $out): bool
    {
        $offset = $this->dataOffset($entry);
        $inflate = $entry['method'] === self::METHOD_DEFLATE ? inflate_init(ZLIB_ENCODING_RAW) : null;
        if ($entry['method'] === self::METHOD_DEFLATE && $inflate === false) {
            throw new ArchiveReaderUnavailableException(sprintf(
                "the zlib inflater refused to start for archive entry '%s'",
                $entry['name'],
            ));
        }

        $checksum = hash_init('crc32b');
        $remaining = $entry['compressedSize'];
        $written = 0;

        while ($remaining > 0) {
            $chunk = $this->readAt($offset, min($remaining, self::READ_CHUNK_BYTES));
            if ($chunk === '') {
                throw new UnrecognizedMigrationFileException(sprintf(
                    "archive entry '%s' stops before the length its header declares",
                    $entry['name'],
                ));
            }

            $offset += strlen($chunk);
            $remaining -= strlen($chunk);

            // The last chunk has to close the stream, or inflate_add holds
            // back whatever the final block still owes the caller.
            $flush = $remaining === 0 ? ZLIB_FINISH : ZLIB_NO_FLUSH;
            $plain = $inflate === null ? $chunk : (string) inflate_add($inflate, $chunk, $flush);

            hash_update($checksum, $plain);
            $written += strlen($plain);
            if ($plain !== '' && fwrite($out, $plain) === false) {
                return false;
            }
        }

        $this->guardEntryArrivedWhole($entry, $written, hash_final($checksum));

        return true;
    }

    /**
     * @param  CentralEntry  $entry
     */
    private function guardEntryArrivedWhole(array $entry, int $written, string $checksum): void
    {
        if ($written !== $entry['uncompressedSize']) {
            throw new UnrecognizedMigrationFileException(sprintf(
                "archive entry '%s' inflated to %d bytes where its header declares %d",
                $entry['name'],
                $written,
                $entry['uncompressedSize'],
            ));
        }

        if ($checksum !== sprintf('%08x', $entry['crc32'])) {
            throw new UnrecognizedMigrationFileException(sprintf(
                "archive entry '%s' fails its own CRC32 check",
                $entry['name'],
            ));
        }
    }

    /**
     * @param  CentralEntry  $entry
     */
    private function dataOffset(array $entry): int
    {
        $header = $this->readAt($entry['localHeaderOffset'], self::LOCAL_FIXED_BYTES);
        if (strlen($header) < self::LOCAL_FIXED_BYTES || ! str_starts_with($header, self::LOCAL_SIGNATURE)) {
            throw new UnrecognizedMigrationFileException(sprintf(
                "archive entry '%s' points at no local file header",
                $entry['name'],
            ));
        }

        // Read from the LOCAL header, not the central one: a packer is free to
        // write a different extra field in each, and the payload begins after
        // the local copy.
        return $entry['localHeaderOffset']
            + self::LOCAL_FIXED_BYTES
            + $this->readShort($header, 26)
            + $this->readShort($header, 28);
    }

    private function fileSize(string $path): int
    {
        $handle = $this->opened();
        if (fseek($handle, 0, SEEK_END) !== 0) {
            throw new UnrecognizedMigrationFileException("could not measure '{$path}'");
        }

        $size = ftell($handle);
        if ($size === false || $size < self::EOCD_FIXED_BYTES) {
            throw new UnrecognizedMigrationFileException("'{$path}' is too short to be a zip archive");
        }

        return $size;
    }

    private function readAt(int $offset, int $length): string
    {
        if ($length <= 0 || $offset < 0) {
            return '';
        }

        $handle = $this->opened();
        if (fseek($handle, $offset) !== 0) {
            throw new UnrecognizedMigrationFileException("could not seek to byte {$offset} of the archive");
        }

        $read = fread($handle, $length);

        return $read === false ? '' : $read;
    }

    private function readShort(string $bytes, int $offset): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('v', substr($bytes, $offset, 2));

        return $unpacked[1];
    }

    private function readLong(string $bytes, int $offset): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('V', substr($bytes, $offset, 4));

        return $unpacked[1];
    }
}
