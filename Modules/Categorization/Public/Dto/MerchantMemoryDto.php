<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

final class MerchantMemoryDto extends Data
{
    public function __construct(
        public readonly int $memoryId,
        public readonly int $categoryId,
        public readonly int $occurrenceCount,
        public readonly ?DateTimeImmutable $lastSeenAt,
    ) {}
}
