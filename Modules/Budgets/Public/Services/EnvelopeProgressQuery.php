<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Modules\Budgets\Public\Dto\BudgetProgressRow;
use Modules\Budgets\Public\Dto\EnvelopeRow;
use Modules\Budgets\Public\Enums\BudgetProgressStatus;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\LocaleCollator;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Support\OutwardSpend;

final readonly class EnvelopeProgressQuery
{
    private const float NEAR_THRESHOLD = 0.8;

    public function __construct(private CarryoverQuery $carryover) {}

    /**
     * @return list<BudgetProgressRow>
     */
    public function forPeriod(User $user, Period $period): array
    {
        $rows = [];
        foreach ($this->carryover->forUserAndPeriod($user, $period)['rows'] as $envelope) {
            $row = self::progressFor($envelope);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        // Alphabetical by what the reader sees, not by what is stored — the
        // stored English orders a Dutch budget summary by the wrong word.
        usort($rows, static function (BudgetProgressRow $a, BudgetProgressRow $b): int {
            $byName = LocaleCollator::compare($a->name, $b->name);

            return $byName !== 0 ? $byName : $a->categoryId <=> $b->categoryId;
        });

        return $rows;
    }

    // Every expense category is an envelope, so one untouched by assignment,
    // carry, move or spend is not a budget the reader set. Reporting it anyway
    // puts a row on a first-run reader's digest that they never made.
    private static function progressFor(EnvelopeRow $envelope): ?BudgetProgressRow
    {
        $budgetMinor = $envelope->availableMinor + $envelope->spentMinor;
        if ($budgetMinor === 0 && $envelope->spentMinor === 0) {
            return null;
        }

        $fraction = OutwardSpend::share($envelope->spentMinor, $budgetMinor);

        return new BudgetProgressRow(
            categoryId: $envelope->categoryId,
            name: $envelope->categoryPath,
            budgetMinor: $budgetMinor,
            spentMinor: $envelope->spentMinor,
            currency: $envelope->currency,
            fractionUsed: $fraction,
            status: self::statusFor($envelope->availableMinor, $fraction),
        );
    }

    // Over is the envelope model's own overspent test — available below zero —
    // and not the fraction, which reads zero for a category that was never
    // assigned anything and so can never divide.
    private static function statusFor(int $availableMinor, float $fraction): BudgetProgressStatus
    {
        return match (true) {
            $availableMinor < 0 => BudgetProgressStatus::Over,
            $fraction >= self::NEAR_THRESHOLD => BudgetProgressStatus::Near,
            default => BudgetProgressStatus::Under,
        };
    }
}
