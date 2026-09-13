<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use HashContext;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;

// ZipExtractor's total-uncompressed cap adds up what the index DECLARES, and
// nothing held an extraction to it: an archive announcing one byte put
// 10,485,760 on disk under a 200MB ceiling that had counted 1. Both readers
// keep this ledger as the bytes arrive, so the cap describes the extraction.
/**
 * @link ../../../../../.docs/features/migration/reading-a-zip-without-ext-zip.md
 */
final class EntryAsDeclared
{
    private int $written = 0;

    private readonly HashContext $checksum;

    public function __construct(
        private readonly string $name,
        private readonly int $size,
        private readonly int $crc32,
    ) {
        $this->checksum = hash_init('crc32b');
    }

    // How much this entry still owes. Both readers size their next read from
    // it, so a header that lies about its size buys one read rather than a
    // disk: the same 256KB read of a hostile deflate stream inflated to
    // 257.6MB in a single string before anything could look at it.
    public function outstanding(): int
    {
        return $this->size - $this->written;
    }

    /**
     * @throws UnrecognizedMigrationFileException when the entry runs past what it declared
     */
    public function accept(string $bytes): void
    {
        $this->written += strlen($bytes);

        if ($this->written > $this->size) {
            throw new UnrecognizedMigrationFileException(sprintf(
                "archive entry '%s' expands past the %d bytes its header declares",
                $this->name,
                $this->size,
            ));
        }

        hash_update($this->checksum, $bytes);
    }

    /**
     * @throws UnrecognizedMigrationFileException when the entry stopped short, or is not its own bytes
     */
    public function sealed(): void
    {
        if ($this->written < $this->size) {
            throw new UnrecognizedMigrationFileException(sprintf(
                "archive entry '%s' expanded to %d bytes where its header declares %d",
                $this->name,
                $this->written,
                $this->size,
            ));
        }

        if (hash_final($this->checksum) !== sprintf('%08x', $this->crc32)) {
            throw new UnrecognizedMigrationFileException(sprintf(
                "archive entry '%s' fails its own CRC32 check",
                $this->name,
            ));
        }
    }
}
