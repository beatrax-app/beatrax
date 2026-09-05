<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use HashContext;
use Modules\Core\Public\Exceptions\BackupIoException;

/**
 * @phpstan-type EntrySizes array{crc32: int, compressedSize: int, uncompressedSize: int}
 * @phpstan-type PackedEntry array{
 *     name: string,
 *     crc32: int,
 *     compressedSize: int,
 *     uncompressedSize: int,
 *     localHeaderOffset: int,
 *     dosDate: int,
 *     dosTime: int,
 * }
 *
 * @link ../../../../.docs/features/migration/reading-a-zip-without-ext-zip.md
 */
final class NativeZipWriter implements ArchiveWriter
{
    // Every write failure means the same thing to the reader — the archive on
    // disk is not one — so the three sites that can hit it say it once.
    private const string WRITE_FAILED = 'The export archive could not be written.';

    private const string LOCAL_SIGNATURE = "PK\x03\x04";

    private const string CENTRAL_SIGNATURE = "PK\x01\x02";

    private const string EOCD_SIGNATURE = "PK\x05\x06";

    private const int VERSION_TO_EXTRACT = 20;

    // Bit 11 tells the reader the name is UTF-8. Without it a non-ASCII entry
    // name is read back through whatever code page the opener guesses, which
    // is how a receipt filed under an accented payee comes out mojibake.
    private const int FLAG_UTF8_NAMES = 0x0800;

    private const int METHOD_DEFLATE = 8;

    private const int LOCAL_HEADER_CRC_OFFSET = 14;

    private const int DEFLATE_CHUNK_BYTES = 65536;

    // Every size and offset a ZIP states outside ZIP64 is a four-byte field and
    // its entry count a two-byte one. This writer does not implement ZIP64, so
    // a value that would not fit is refused here rather than truncated into an
    // archive that opens and then reads short.
    private const int MAX_ZIP32_BYTES = 0xFFFFFFFF;

    private const int MAX_ZIP32_ENTRIES = 0xFFFF;

    private const int MAX_NAME_BYTES = 0xFFFF;

    private const int DOS_EPOCH_YEAR = 1980;

    private const int DOS_LAST_YEAR = 2107;

    private const array FALLBACK_DOS_STAMP = [0x0021, 0];

    /** @var resource|null */
    private $handle = null;

    /** @var list<PackedEntry> */
    private array $entries = [];

    private int $offset = 0;

    public function open(string $path): void
    {
        $handle = @fopen($path, 'w+b');
        if ($handle === false) {
            throw new BackupIoException('The export archive could not be opened for writing.');
        }

        $this->handle = $handle;
        $this->entries = [];
        $this->offset = 0;
    }

    public function addFile(string $sourcePath, string $entryName): void
    {
        $this->opened();

        $name = $this->entryNameFor($entryName);
        $this->guardRoomForAnotherEntry();

        $source = $this->openSource($sourcePath, $name);
        $localHeaderOffset = $this->offset;
        [$dosDate, $dosTime] = $this->dosStamp($sourcePath);

        try {
            $this->write($this->localHeader($name, $dosDate, $dosTime));
            $sizes = $this->streamEntry($source, $name);
        } finally {
            fclose($source);
        }

        $this->patchLocalHeader($localHeaderOffset, $sizes);

        $this->entries[] = [
            'name' => $name,
            'crc32' => $sizes['crc32'],
            'compressedSize' => $sizes['compressedSize'],
            'uncompressedSize' => $sizes['uncompressedSize'],
            'localHeaderOffset' => $localHeaderOffset,
            'dosDate' => $dosDate,
            'dosTime' => $dosTime,
        ];
    }

    // The writer is emptied before the handle is closed, so a close that fails
    // still leaves nothing half-open for a second finish() to write into.
    public function finish(): void
    {
        $handle = $this->opened();
        $centralOffset = $this->offset;

        foreach ($this->entries as $entry) {
            $this->write($this->centralHeader($entry).$entry['name']);
        }

        $this->write($this->endOfCentralDirectory($centralOffset, $this->offset - $centralOffset, count($this->entries)));

        $this->handle = null;
        $this->entries = [];
        $this->offset = 0;

        if (! fclose($handle)) {
            throw new BackupIoException(self::WRITE_FAILED);
        }
    }

    /**
     * @return resource
     */
    private function opened()
    {
        if (! is_resource($this->handle)) {
            throw new BackupIoException('The export archive was written to before it was opened.');
        }

        return $this->handle;
    }

    /**
     * @return resource
     */
    private function openSource(string $sourcePath, string $entryName)
    {
        $size = is_file($sourcePath) ? @filesize($sourcePath) : false;
        if ($size === false) {
            throw new BackupIoException('A file could not be added to the export archive: '.$entryName);
        }

        $this->guardEntryFits($size, $entryName);

        $source = @fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new BackupIoException('A file could not be added to the export archive: '.$entryName);
        }

        return $source;
    }

    /**
     * @param  resource  $source
     * @return EntrySizes
     */
    private function streamEntry($source, string $entryName): array
    {
        $deflate = deflate_init(ZLIB_ENCODING_RAW);
        if ($deflate === false) {
            throw new BackupIoException('The deflater refused to start for an export archive entry: '.$entryName);
        }

        $checksum = hash_init('crc32b');
        $uncompressed = 0;
        $compressed = 0;

        while (true) {
            $chunk = fread($source, self::DEFLATE_CHUNK_BYTES);
            if ($chunk === false) {
                throw new BackupIoException('A file could not be read into the export archive: '.$entryName);
            }

            // The last chunk has to close the stream, or deflate_add holds back
            // whatever the final block still owes. A zero-byte source arrives
            // here having read nothing and still has to emit that block.
            $last = $chunk === '' || feof($source);
            $packed = deflate_add($deflate, $chunk, $last ? ZLIB_FINISH : ZLIB_NO_FLUSH);
            if ($packed === false) {
                throw new BackupIoException('A file could not be compressed into the export archive: '.$entryName);
            }

            hash_update($checksum, $chunk);
            $uncompressed += strlen($chunk);
            $compressed += strlen($packed);
            $this->write($packed);

            if ($last) {
                break;
            }
        }

        $this->guardEntryFits($uncompressed, $entryName);

        return [
            'crc32' => $this->checksumOf($checksum),
            'compressedSize' => $compressed,
            'uncompressedSize' => $uncompressed,
        ];
    }

    private function checksumOf(HashContext $checksum): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', hash_final($checksum, true));

        return $unpacked[1];
    }

    // The crc and both sizes are unknown until the entry has been streamed, so
    // they go out as zeroes and are patched afterwards. The archive is a
    // seekable file, which is why no data descriptor is needed -- the form
    // several Windows readers refuse.
    private function localHeader(string $name, int $dosDate, int $dosTime): string
    {
        return self::LOCAL_SIGNATURE.pack(
            'vvvvvVVVvv',
            self::VERSION_TO_EXTRACT,
            self::FLAG_UTF8_NAMES,
            self::METHOD_DEFLATE,
            $dosTime,
            $dosDate,
            0,
            0,
            0,
            strlen($name),
            0,
        ).$name;
    }

    /**
     * @param  EntrySizes  $sizes
     */
    private function patchLocalHeader(int $localHeaderOffset, array $sizes): void
    {
        $handle = $this->opened();
        $patch = pack('VVV', $sizes['crc32'], $sizes['compressedSize'], $sizes['uncompressedSize']);

        if (fseek($handle, $localHeaderOffset + self::LOCAL_HEADER_CRC_OFFSET) !== 0
            || fwrite($handle, $patch) !== strlen($patch)
            || fseek($handle, $this->offset) !== 0) {
            throw new BackupIoException(self::WRITE_FAILED);
        }
    }

    /**
     * @param  PackedEntry  $entry
     */
    private function centralHeader(array $entry): string
    {
        return self::CENTRAL_SIGNATURE.pack(
            'vvvvvvVVVvvvvvVV',
            self::VERSION_TO_EXTRACT,
            self::VERSION_TO_EXTRACT,
            self::FLAG_UTF8_NAMES,
            self::METHOD_DEFLATE,
            $entry['dosTime'],
            $entry['dosDate'],
            $entry['crc32'],
            $entry['compressedSize'],
            $entry['uncompressedSize'],
            strlen($entry['name']),
            0,
            0,
            0,
            0,
            0,
            $entry['localHeaderOffset'],
        );
    }

    private function endOfCentralDirectory(int $centralOffset, int $centralSize, int $entries): string
    {
        return self::EOCD_SIGNATURE.pack(
            'vvvvVVv',
            0,
            0,
            $entries,
            $entries,
            $centralSize,
            $centralOffset,
            0,
        );
    }

    private function write(string $bytes): void
    {
        $handle = $this->opened();
        $written = $bytes === '' ? 0 : @fwrite($handle, $bytes);
        if ($written !== strlen($bytes)) {
            throw new BackupIoException(self::WRITE_FAILED);
        }

        $this->offset += $written;

        if ($this->offset > self::MAX_ZIP32_BYTES) {
            throw new BackupIoException(sprintf(
                'The export archive passes the %d bytes a ZIP can address without ZIP64.',
                self::MAX_ZIP32_BYTES,
            ));
        }
    }

    private function entryNameFor(string $entryName): string
    {
        $name = ltrim(str_replace('\\', '/', $entryName), '/');

        if ($name === '' || strlen($name) > self::MAX_NAME_BYTES) {
            throw new BackupIoException(sprintf(
                'An export archive entry name has to be between 1 and %d bytes: %s',
                self::MAX_NAME_BYTES,
                $entryName,
            ));
        }

        return $name;
    }

    private function guardRoomForAnotherEntry(): void
    {
        if (count($this->entries) >= self::MAX_ZIP32_ENTRIES) {
            throw new BackupIoException(sprintf(
                'The export archive cannot hold more than %d files without ZIP64.',
                self::MAX_ZIP32_ENTRIES,
            ));
        }
    }

    private function guardEntryFits(int $bytes, string $entryName): void
    {
        if ($bytes > self::MAX_ZIP32_BYTES) {
            throw new BackupIoException(sprintf(
                'A file is larger than the %d bytes a ZIP entry can declare without ZIP64: %s',
                self::MAX_ZIP32_BYTES,
                $entryName,
            ));
        }
    }

    // A file whose mtime cannot be read, or one dated outside the window the
    // two DOS fields can count, is stamped with the epoch those fields start
    // from: a date that reads as 1980, never an entry no opener will take.
    /**
     * @return array{0: int, 1: int}
     */
    private function dosStamp(string $sourcePath): array
    {
        $mtime = @filemtime($sourcePath);
        if ($mtime === false) {
            return self::FALLBACK_DOS_STAMP;
        }

        $parts = getdate($mtime);
        if ($parts['year'] < self::DOS_EPOCH_YEAR || $parts['year'] > self::DOS_LAST_YEAR) {
            return self::FALLBACK_DOS_STAMP;
        }

        return [
            (($parts['year'] - self::DOS_EPOCH_YEAR) << 9) | ($parts['mon'] << 5) | $parts['mday'],
            ($parts['hours'] << 11) | ($parts['minutes'] << 5) | intdiv($parts['seconds'], 2),
        ];
    }
}
