<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Spatie\LaravelData\Data;

/**
 * Output shape of `ApplyAutoCategoryStage`.
 *
 * Three branches:
 *
 *  - `auto(...)` — a `CategorizationRule` or a `merchant_memories`
 *    row produced a winning candidate. `provenance` is `'rule'` or
 *    `'memory'`; the matching `ruleId` / `memoryId` is preserved so
 *    the correction-divergence flow (toast + inline drawer panel)
 *    knows where the suggestion came from.
 *
 *  - `manual(...)` — no auto-categorization candidate scored above
 *    threshold; the row reaches `RecordTransactions` with
 *    `category_id=null` and surfaces on `/uncategorized` for human
 *    triage.
 *
 * The wrapped `CanonicalTransaction` carries the (potentially
 * auto-categorised) row through to the writer; the stage updates the
 * row's `categoryId` + `autoCategoryProvenance` JSON in place
 * downstream.
 */
final class AutoCategorizationOutcomeDto extends Data
{
    public function __construct(
        public readonly CanonicalTransaction $canonical,
        public readonly string $provenance,
        public readonly ?int $ruleId,
        public readonly ?int $memoryId,
    ) {}

    public static function auto(
        CanonicalTransaction $canonical,
        string $provenance,
        ?int $ruleId,
        ?int $memoryId,
    ): self {
        return new self(
            canonical: $canonical,
            provenance: $provenance,
            ruleId: $ruleId,
            memoryId: $memoryId,
        );
    }

    public static function manual(CanonicalTransaction $canonical): self
    {
        return new self(
            canonical: $canonical,
            provenance: 'manual',
            ruleId: null,
            memoryId: null,
        );
    }
}
