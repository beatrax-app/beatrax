<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

// `mergedFrom` preserves provenance on the survivor of a bulk merge; it is
// null on a row that was never merged.
final class MerchantAliasDto extends Data
{
    /**
     * @param  array<int, array<string, mixed>>|null  $mergedFrom
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $pattern,
        public readonly string $generalizedPattern,
        public readonly string $friendlyName,
        public readonly ?array $mergedFrom,
        public readonly CarbonImmutable $createdAt,
    ) {}
}
