<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Spatie\LaravelData\Data;

final class UnmappedItemDto extends Data
{
    public function __construct(
        public readonly string $itemType,
        public readonly ?string $sourceExternalId,
        public readonly string $displayLabel,
        public readonly string $reason,
    ) {}
}
