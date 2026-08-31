<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Migration\Internal\Exceptions\ArchiveReaderUnavailableException;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;

/**
 * @link ../../../../../.docs/features/migration/reading-a-zip-without-ext-zip.md
 */
interface ArchiveReader
{
    /**
     * @throws UnrecognizedMigrationFileException When the bytes are not a readable archive.
     * @throws ArchiveReaderUnavailableException When they are, and this build cannot read them.
     */
    public function open(string $path): void;

    /**
     * @throws UnrecognizedMigrationFileException
     */
    public function entryCount(): int;

    /**
     * @return list<ArchiveEntry>
     *
     * @throws UnrecognizedMigrationFileException
     */
    public function index(): array;

    public function extractTo(string $directory): bool;

    public function close(): void;
}
