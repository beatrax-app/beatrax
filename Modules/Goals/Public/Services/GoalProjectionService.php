<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Public\ValueObjects\Money;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Computes a projected finish date for a savings goal using a contribution
 * run-rate over a trailing window, validated against the Forecasting engine
 * where the forecast horizon covers the projected date.
 *
 * Algorithm (GOAL-04 / D-07):
 *  1. Unlinked goal (account_id null) → null date, beyondHorizon=false.
 *  2. Already reached (contributed >= target) → null date, beyondHorizon=false.
 *  3. Compute dailyRateMinor from contributions over trailing TRAILING_WINDOW_DAYS.
 *  4. dailyRateMinor <= 0 → null date (no history or stagnant).
 *  5. daysToFinish = ceil((target - contributed) / dailyRate); projectedDate = today + daysToFinish.
 *  6. daysToFinish <= HORIZON_LIMIT_DAYS → in-horizon (beyondHorizon=false),
 *     optionally validated against ForecastQuery; any NotFoundHttpException is
 *     caught defensively (FK-constraint means it will not occur in practice).
 *  7. daysToFinish > HORIZON_LIMIT_DAYS → extrapolated (beyondHorizon=true); do
 *     NOT call ForecastQuery beyond 90d (Pitfall 3).
 *
 * TRAILING_WINDOW_DAYS and HORIZON_LIMIT_DAYS are named constants so they can be
 * adjusted together if the forecast horizon changes (D-07 tunables — Claude's
 * discretion; 90d aligns the two windows).
 *
 * Pitfall 4 compliance: pointMinor in ForecastDto is the account balance, NOT goal
 * contributions. This class never reads pointMinor as a contribution figure.
 */
final class GoalProjectionService
{
    /** Trailing window (days) over which the contribution run-rate is computed. */
    private const int TRAILING_WINDOW_DAYS = 90;

    /** Maximum forecast horizon — project dates beyond this are extrapolated (lower-confidence). */
    private const int HORIZON_LIMIT_DAYS = 90;

    /**
     * Minimum observation history (days) before a run-rate projection is shown.
     * Below this, one early deposit would extrapolate a misleadingly-soon finish
     * (a goal created today divides by a 1-day window). Until enough signal
     * accrues the card shows the "building a projection" copy instead.
     */
    private const int MIN_OBSERVATION_DAYS = 7;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ExchangeRateService $fx,
        private readonly ForecastQuery $forecast,
    ) {}

    /**
     * Returns ['date' => ?string, 'beyondHorizon' => bool].
     *
     * @return array{date: ?string, beyondHorizon: bool}
     */
    public function project(Goal $goal, int $contributedMinor, User $user): array
    {
        // Guard 1: unlinked goal.
        if ($goal->account_id === null) {
            return ['date' => null, 'beyondHorizon' => false];
        }

        $accountId = $goal->account_id;

        // Guard 2: already reached.
        if ($contributedMinor >= $goal->target_minor) {
            return ['date' => null, 'beyondHorizon' => false];
        }

        // Compute the trailing-window contribution sum.
        $windowStart = CarbonImmutable::today()->subDays(self::TRAILING_WINDOW_DAYS)->toDateString();
        // Bound by the goal's own start_date (cannot have contributions before start).
        $effectiveStart = $goal->start_date->toDateString() > $windowStart
            ? $goal->start_date->toDateString()
            : $windowStart;

        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->whereIn('type', ['transfer_in', 'income'])
            ->where('posted_at', '>=', $effectiveStart)
            ->get(['amount_minor', 'currency']);

        // Convert the window's contributions into the goal's own target_currency
        // so the run-rate numerator shares a unit with target_minor (the
        // remaining-amount denominator). Using base_currency here would mix units
        // for any goal whose target_currency diverges from base_currency (D-05).
        $windowSum = 0;
        foreach ($rows as $r) {
            $money = Money::ofMinor(self::toInt($r->amount_minor), self::toStr($r->currency));
            $result = $this->fx->convertToBase($money, $goal->target_currency);
            $windowSum += $result->converted->toMinor();
        }

        // dailyRateMinor = sum over the window / the ACTUAL elapsed observation
        // window (not the constant 90). For a goal younger than TRAILING_WINDOW_DAYS,
        // $effectiveStart is clamped to its start_date, so dividing by 90 would
        // systematically understate the rate (and overstate the finish date). Use
        // the real elapsed days, floored at 1 to avoid a divide-by-zero on a goal
        // created today.
        // Guard 3: not enough observation history for a credible run-rate.
        // A goal only a few days old would otherwise divide its first deposit by
        // a 1-day window and project a finish a day or two out (WR-06). Suppress
        // the projected date until MIN_OBSERVATION_DAYS of history exist.
        $elapsedDays = CarbonImmutable::parse($effectiveStart)->diffInDays(CarbonImmutable::today());
        if ($elapsedDays < self::MIN_OBSERVATION_DAYS) {
            return ['date' => null, 'beyondHorizon' => false];
        }

        $observationDays = max(1, $elapsedDays);
        $dailyRateMinor = $windowSum / $observationDays;

        if ($dailyRateMinor <= 0.0) {
            return ['date' => null, 'beyondHorizon' => false];
        }

        $remainingMinor = $goal->target_minor - $contributedMinor;
        $daysToFinish = (int) ceil($remainingMinor / $dailyRateMinor);
        $projectedDate = CarbonImmutable::today()->addDays($daysToFinish)->format('Y-m-d');

        if ($daysToFinish > self::HORIZON_LIMIT_DAYS) {
            // Beyond forecast range — extrapolated, lower-confidence.
            return ['date' => $projectedDate, 'beyondHorizon' => true];
        }

        // In-horizon: select the smallest covering horizon from {30, 60, 90}.
        $horizon = $this->coveringHorizon($daysToFinish);

        try {
            // Validate that the forecast is available (not computing).
            // We use the forecast as a sanity signal only — do NOT read
            // pointMinor as a goal contribution figure (Pitfall 4).
            $this->forecast->forUser($accountId, $horizon, null, $user);
            // If we reach here the forecast is complete → in-horizon, high confidence.
        } catch (NotFoundHttpException) {
            // Cross-user or missing account — should not occur given FK + scope,
            // but be defensive: fall back to run-rate only.
        }

        return ['date' => $projectedDate, 'beyondHorizon' => false];
    }

    /**
     * Returns the smallest horizon in {30, 60, 90} that covers $daysToFinish.
     * Caller guarantees $daysToFinish <= HORIZON_LIMIT_DAYS (= 90).
     */
    private function coveringHorizon(int $daysToFinish): int
    {
        return match (true) {
            $daysToFinish <= 30 => 30,
            $daysToFinish <= 60 => 60,
            default => 90,
        };
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
