<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

// Cross-module read shape for one merchant_aliases row. `mergedFrom` is
// a list of {id, pattern, friendly_name, merged_at} triples preserved
// on the survivor after a bulk-merge action; null on non-merged rows.
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
