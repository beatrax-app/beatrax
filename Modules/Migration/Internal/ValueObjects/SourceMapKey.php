<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\ValueObjects;

// SourceMapWriter::resolve() and record() both key on exactly this tuple, so it
// travels as one value rather than four arguments.
final readonly class SourceMapKey
{
    public function __construct(
        public string $sourceProduct,
        public string $entityType,
        public ?string $sourceExternalId,
        public ?string $naturalKey = null,
    ) {}
}
