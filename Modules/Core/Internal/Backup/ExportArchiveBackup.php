<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use InflateContext;
use Modules\Core\Public\Exceptions\BackupFormatException;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Support\ZipLocalEntry;

/**
 * @phpstan-type BackupEntry array{
 *     method: int,
 *     compressedSize: int,
 *     uncompressedSize: int,
 *     dataOffset: int,
 * }
 *
 * @link ../../../../.docs/features/core/one-export-action.md#taking-the-archive-back-in
 */
final class ExportArchiveBackup
{
    public const string ENTRY_PREFIX = 'beatrax-backup-';

    public const string ENTRY_SUFFIX = '.sqlite.enc';

    // Sizes live in a trailing descriptor rather than in the header when this
    // is set. Neither writer here does that — both patch the header once the
    // entry is on disk — so an archive that carries one is not one of ours.
    private const int FLAG_DATA_DESCRIPTOR = 0x0008;

    // The reader hands back whatever the export gave them, and that is a `.zip`
    // where the backup download is a bare `.enc`. Four bytes tell the two apart
    // without reading either.
    public function isArchive(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $signature = fread($handle, strlen(ZipLocalEntry::SIGNATURE));
        fclose($handle);

        return $signature === ZipLocalEntry::SIGNATURE;
    }

    // Only the backup comes out. The source documents beside it in the archive
    // are the reader's own files and already on their machine; a restore that
    // unpacked them would be writing files nobody asked it to write, at paths
    // the archive rather than this application chose.
    /**
     * @throws BackupFormatException when the archive holds no backup of ours
     * @throws BackupIoException when the entry cannot be read or written
     */
    public function liftBackupInto(string $archivePath, string $targetPath): void
    {
        $handle = @fopen($archivePath, 'rb');
        if ($handle === false) {
            throw new BackupIoException('The export archive could not be opened for reading.');
        }

        try {
            $this->copyEntry($handle, $this->firstEntry($handle), $targetPath);
        } finally {
            fclose($handle);
        }
    }

    // The backup is written before any source document, so it is the archive's
    // first entry and its local header sits at byte zero. Its name is checked
    // rather than assumed: an archive of somebody else's making opens here too,
    // and a mis-parse of one would be reported as a damaged database.
    /**
     * @param  resource  $handle
     * @return BackupEntry
     *
     * @throws BackupFormatException
     */
    private function firstEntry($handle): array
    {
        $header = $this->readAt($handle, 0, ZipLocalEntry::FIXED_BYTES);
        if (strlen($header) < ZipLocalEntry::FIXED_BYTES || ! str_starts_with($header, ZipLocalEntry::SIGNATURE)) {
            throw new BackupFormatException('The archive does not open with a zip entry.');
        }

        $nameLength = $this->readShort($header, 26);
        $name = $this->readAt($handle, ZipLocalEntry::FIXED_BYTES, $nameLength);
        $this->guardIsOurBackup($header, $name);

        return [
            'method' => $this->readShort($header, 8),
            'compressedSize' => $this->readLong($header, 18),
            'uncompressedSize' => $this->readLong($header, 22),
            'dataOffset' => ZipLocalEntry::FIXED_BYTES + $nameLength + $this->readShort($header, 28),
        ];
    }

    /**
     * @throws BackupFormatException
     */
    private function guardIsOurBackup(string $header, string $name): void
    {
        if (! str_starts_with($name, self::ENTRY_PREFIX) || ! str_ends_with($name, self::ENTRY_SUFFIX)) {
            throw new BackupFormatException('The archive holds no Beatrax backup as its first entry.');
        }

        if (($this->readShort($header, 6) & self::FLAG_DATA_DESCRIPTOR) !== 0) {
            throw new BackupFormatException('The archived backup states its length in a trailing descriptor.');
        }

        $method = $this->readShort($header, 8);
        if ($method !== ZipLocalEntry::METHOD_STORE && $method !== ZipLocalEntry::METHOD_DEFLATE) {
            throw new BackupFormatException(sprintf('The archived backup uses compression method %d.', $method));
        }
    }

    /**
     * @param  resource  $handle
     * @param  BackupEntry  $entry
     *
     * @throws BackupFormatException
     * @throws BackupIoException
     */
    private function copyEntry($handle, array $entry, string $targetPath): void
    {
        $out = @fopen($targetPath, 'wb');
        if ($out === false) {
            throw new BackupIoException('The archived backup could not be written out to the restore staging area.');
        }

        try {
            $written = $this->streamEntry($handle, $out, $entry, $this->inflaterFor($entry));
        } finally {
            fclose($out);
        }

        if ($written !== $entry['uncompressedSize']) {
            throw new BackupFormatException(sprintf(
                'The archived backup came out at %d bytes where its header declares %d.',
                $written,
                $entry['uncompressedSize'],
            ));
        }
    }

    /**
     * @param  BackupEntry  $entry
     *
     * @throws BackupIoException
     */
    private function inflaterFor(array $entry): ?InflateContext
    {
        if ($entry['method'] !== ZipLocalEntry::METHOD_DEFLATE) {
            return null;
        }

        $inflate = inflate_init(ZLIB_ENCODING_RAW);
        if ($inflate === false) {
            throw new BackupIoException('The zlib inflater refused to start for the archived backup.');
        }

        return $inflate;
    }

    /**
     * @param  resource  $handle
     * @param  resource  $out
     * @param  BackupEntry  $entry
     * @return int the number of bytes written out
     *
     * @throws BackupFormatException
     * @throws BackupIoException
     */
    private function streamEntry($handle, $out, array $entry, ?InflateContext $inflate): int
    {
        $offset = $entry['dataOffset'];
        $remaining = $entry['compressedSize'];
        $written = 0;

        while ($remaining > 0) {
            $chunk = $this->readAt($handle, $offset, min($remaining, ZipLocalEntry::READ_CHUNK_BYTES));
            if ($chunk === '') {
                throw new BackupFormatException('The export archive stops before the end of the backup it holds.');
            }

            $offset += strlen($chunk);
            $remaining -= strlen($chunk);

            // The last chunk has to close the stream, or inflate_add holds back
            // whatever the final block still owes the caller.
            $flush = $remaining === 0 ? ZLIB_FINISH : ZLIB_NO_FLUSH;
            $plain = $inflate instanceof InflateContext
                ? (string) inflate_add($inflate, $chunk, $flush)
                : $chunk;

            $written += strlen($plain);
            if ($plain !== '' && fwrite($out, $plain) === false) {
                throw new BackupIoException('The archived backup could not be written out in full.');
            }
        }

        return $written;
    }

    /**
     * @param  resource  $handle
     */
    private function readAt($handle, int $offset, int $length): string
    {
        if ($length <= 0 || fseek($handle, $offset) !== 0) {
            return '';
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
