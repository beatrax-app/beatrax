<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * The wizard preview's full read-model result (Req 11/12): the five mapped
 * counts the 5-up stat grid renders (categories, accounts, counterparties,
 * transactions, budget months) plus the grouped unmapped-items summary.
 *
 * `transactionsCount` counts distinct staged transactions — a split parent
 * counts once (its legs are additional `migration_staging_transactions` rows
 * with `parent_source_external_id` set, excluded from this count so a
 * 2-leg split does not inflate the stat by 2 extra).
 *
 * `budgetMonthsCount` counts DISTINCT `period_start` values in
 * `migration_staging_budget_assignments` — "how many months of budget
 * history will import", not the total (category, month) assignment row
 * count (which can be several times larger).
 *
 * `unmapped` is keyed by every `UnmappedItemDto::$itemType` value
 * (`'category'|'payee'|'extra'|'conflict'`) — ALWAYS all four keys present,
 * even when empty, so the wizard can render "Everything mapped cleanly" only
 * when every group's `count` is genuinely zero rather than a missing key.
 * `'conflict'` is always empty for a first-time parse (populated only by a
 * reconciliation re-run — Plan 07).
 *
 * Every item carries `id` (the underlying `migration_staging_unmapped_items`
 * row id) so the wizard can act on a SPECIFIC row (13.5-HUMAN-UAT.md Test 3c
 * gap-fix: `PreviewMigration::resolveConflict()` persists a resolution
 * keyed by this id). `resolution` is only meaningful for the `'conflict'`
 * group (`'keep_local'` | `'take_source'`, defaulted from a NULL DB value)
 * — every other group carries `'keep_local'` as an unused filler so the
 * array shape stays uniform.
 */
final class PreviewSummary extends Data
{
    /**
     * @param  array<string, array{items: list<array{id: int, label: string, reason: string, resolution: string}>, count: int}>  $unmapped
     */
    public function __construct(
        public readonly int $categoriesCount,
        public readonly int $accountsCount,
        public readonly int $counterpartiesCount,
        public readonly int $transactionsCount,
        public readonly int $budgetMonthsCount,
        public readonly array $unmapped,
    ) {}
}
