<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * One (category, month) budgeted-amount row (YNAB4/nYNAB `Budget.csv`, or
 * Actual's `zero_budgets` — populated only when `preferences.budgetType`
 * is `'envelope'`, per RESEARCH.md's Format Schemas; `reflect_budgets`
 * ("tracking" mode) is out of scope for Req 3, which targets beatrax's own
 * envelope model).
 *
 * `budgeted` is the assigned amount ONLY — never a carried-forward balance
 * (YNAB4's `Category Balance` / Actual's derived running total). Per Req 3's
 * explicit acceptance criterion, beatrax's own `CarryoverQuery` derives
 * balances; the promote step feeds `budgeted` straight into
 * `EnvelopeWriter::setAssigned()` and nothing else.
 */
final class MigrationBudgetAssignmentDto extends Data
{
    public function __construct(
        public readonly string $sourceCategoryExternalId,
        public readonly CarbonImmutable $periodStart,
        public readonly Money $budgeted,
    ) {}
}
