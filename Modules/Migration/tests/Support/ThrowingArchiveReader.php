<?php

declare(strict_types=1);

namespace Modules\Migration\Tests\Support;

use Modules\Migration\Internal\Parsers\Support\ArchiveEntry;
use Modules\Migration\Internal\Parsers\Support\ArchiveReader;
use Throwable;

// Stands in for what the phone answered before ext-zip had a fallback: the very
// first call into the archive raises a bare PHP Error rather than any typed
// parser failure, which is the ending NewMigration used to blame on the file.
final class ThrowingArchiveReader implements ArchiveReader
{
    public function __construct(private readonly Throwable $failure) {}

    public function open(string $path): void
    {
        throw $this->failure;
    }

    public function entryCount(): int
    {
        throw $this->failure;
    }

    /**
     * @return list<ArchiveEntry>
     */
    public function index(): array
    {
        throw $this->failure;
    }

    public function extractTo(string $directory): bool
    {
        throw $this->failure;
    }

    public function close(): void {}
}
