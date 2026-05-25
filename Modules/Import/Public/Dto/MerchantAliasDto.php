<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Public DTO that wraps one row of the per-user merchant_aliases table.
 *
 * The DTO is the cross-module read shape: the rename popover emits it
 * after a save, the Settings → Aliases page consumes it for its row
 * cards, and the resolver test suites use it to assert state. Carrying
 * the `generalizedPattern` alongside `pattern` lets every consumer
 * render the auto-generalize preview line without rerunning the
 * PatternGeneralizer service.
 *
 * `mergedFrom` carries the bulk-merge provenance: a list of
 * `{id, pattern, friendly_name, merged_at}` triples preserved on the
 * surviving row after a Settings → Aliases merge action. Null on every
 * non-merged row.
 */
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
