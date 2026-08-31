<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

/**
 * @link ../../../../../.docs/features/migration/reading-a-zip-without-ext-zip.md
 */
final readonly class ArchiveEntry
{
    public function __construct(
        public string $name,
        public int $uncompressedSize,
        public bool $isSymlink,
    ) {}
}
