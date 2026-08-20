<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\ValueObjects;

// The identity of one migration_source_map row: which source
// product/entity-type a foreign id — or, for id-less entities, a
// natural key — belongs to. SourceMapWriter::resolve()/record() both
// key on exactly this tuple, so it travels as one value, not four args.
final readonly class SourceMapKey
{
    public function __construct(
        public string $sourceProduct,
        public string $entityType,
        public ?string $sourceExternalId,
        public ?string $naturalKey = null,
    ) {}
}
