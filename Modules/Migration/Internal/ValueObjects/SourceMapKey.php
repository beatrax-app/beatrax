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
        // Null at all fourteen production call sites, so the column it writes is
        // uniformly empty. The first caller to pass one changes that: a natural
        // key is the payee's own name, SourceMapWriter matches on it by equality,
        // and a value both readable and matched is a registry decision first.
        public ?string $naturalKey = null,
    ) {}
}
