<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

/**
 * Read-side projection of one merchant_memories row.
 *
 * Returned by MerchantMemoryQuery so the rule-evaluator + future
 * correction-divergence panel can read learned categorization without
 * importing the Categorization Internal classes.
 *
 * `lastSeenAt` is nullable because merchant_memories may have been
 * pre-seeded with occurrence_count = 0 (currently unused, but the
 * column is nullable per the migration).
 */
final class MerchantMemoryDto extends Data
{
    public function __construct(
        public readonly int $memoryId,
        public readonly int $categoryId,
        public readonly int $occurrenceCount,
        public readonly ?DateTimeImmutable $lastSeenAt,
    ) {}
}
