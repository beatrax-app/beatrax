<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\PeriodQuery;

// The span every demo seeder writes into: the reader's own current budget
// period and the two before it. The budgets grid is drawn over exactly these
// three windows and its back control clamps at the oldest, so this is the
// definition and the ledger's rows derive from it, not the other way round.

// It lives beside the seeders rather than in the Public seam because nothing
// the reader ever runs may depend on it.
/**
 * @link ../../../../../.docs/conventions/invariants-from-shipped-failures.md#a-window-recomputed-instead-of-derived
 */
final readonly class DemoPeriodWindow
{
    // One period of assignments made genesis and the period on screen the same
    // month, and the fold then had nothing to fold: carry-in was nought in
    // every row, both overspend modes read alike, and the back control never
    // moved.
    public const int SPAN = 3;

    public function __construct(
        private PeriodQuery $periods,
    ) {}

    /**
     * @return list<Period> oldest first, the current period last
     */
    public function forUser(User $user, CarbonImmutable $now): array
    {
        $window = [$this->periods->containingForUser($user, $now)];

        for ($i = 1; $i < self::SPAN; $i++) {
            array_unshift($window, $this->periods->previous($window[0]));
        }

        return $window;
    }

    // The one day inside $period carrying $dayOfMonth, clamped to the length of
    // whichever month that turns out to be. A period opening on the 25th spans
    // two calendar months, so setDay(1) on its start lands 24 days BEFORE the
    // period, and the second branch is what reaches the later month instead.
    public static function dayIn(Period $period, int $dayOfMonth): CarbonImmutable
    {
        $candidate = self::clampedDay($period->start, $dayOfMonth);
        if ($candidate->greaterThanOrEqualTo($period->start)) {
            return $candidate;
        }

        return self::clampedDay($period->start->addMonthNoOverflow(), $dayOfMonth);
    }

    private static function clampedDay(CarbonImmutable $month, int $dayOfMonth): CarbonImmutable
    {
        return $month->setDay(min($dayOfMonth, $month->daysInMonth))->startOfDay();
    }
}
