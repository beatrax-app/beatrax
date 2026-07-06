<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * A category savings goal, reachable ONLY from the Actual source
 * (`categories.goal_def`) — YNAB4/nYNAB's CSV export carries no goal/target
 * columns at all (RESEARCH.md Format Schemas, Summary finding 1). Maps onto
 * `Modules\Goals\Public\Services\GoalWriter::save()`'s
 * `(name, rawAmount, targetDate, accountId)` shape; the promote step passes
 * `accountId: null` since Actual's goal template is category-scoped, not
 * account-scoped.
 *
 * ONLY emitted for a "FLAT" `goal_def` — a single target amount + optional
 * target date. Actual's `goal_def` grammar also supports non-flat templates
 * (percentage-of-income, multi-step schedules); those reduce to an
 * `UnmappedItemDto` instead (Req 8's "reported as unmapped, not dropped"),
 * never silently coerced into a lossy flat approximation.
 */
final class MigrationGoalDto extends Data
{
    public function __construct(
        public readonly string $categorySourceExternalId,
        public readonly string $name,
        public readonly Money $targetAmount,
        public readonly ?CarbonImmutable $targetDate,
    ) {}
}
