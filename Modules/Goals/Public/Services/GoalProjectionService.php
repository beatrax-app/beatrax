<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Public\ValueObjects\Money;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/goals/architecture.md
 */
final class GoalProjectionService
{
    use CoercesScalars;

    private const int TRAILING_WINDOW_DAYS = 90;

    private const int HORIZON_LIMIT_DAYS = 90;

    // Below this, one early deposit would extrapolate a misleadingly-soon
    // finish (a goal created today divides by a 1-day window) — the card
    // shows "building a projection" copy instead until enough signal accrues.
    private const int MIN_OBSERVATION_DAYS = 7;

    /** @var array{date: null, beyondHorizon: false} */
    private const array NO_PROJECTION = ['date' => null, 'beyondHorizon' => false];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ExchangeRateService $fx,
        private readonly ForecastQuery $forecast,
    ) {}

    /**
     * @return array{date: ?string, beyondHorizon: bool}
     */
    public function project(Goal $goal, int $contributedMinor, User $user): array
    {
        $accountId = $goal->account_id;
        if ($accountId === null || $contributedMinor >= $goal->target_minor) {
            return self::NO_PROJECTION;
        }

        $dailyRateMinor = $this->dailyContributionRate($goal, $accountId, $user);
        if ($dailyRateMinor <= 0.0) {
            return self::NO_PROJECTION;
        }

        $remainingMinor = $goal->target_minor - $contributedMinor;
        $daysToFinish = (int) ceil($remainingMinor / $dailyRateMinor);
        $projectedDate = CarbonImmutable::today()->addDays($daysToFinish)->format('Y-m-d');
        $beyondHorizon = $daysToFinish > self::HORIZON_LIMIT_DAYS;

        if (! $beyondHorizon) {
            $this->warmForecastCache($accountId, $daysToFinish, $user);
        }

        return ['date' => $projectedDate, 'beyondHorizon' => $beyondHorizon];
    }

    // Run-rate over the trailing window, converted into the goal's own
    // target_currency so the numerator shares a unit with target_minor. Returns
    // 0.0 while the goal is younger than the minimum observation window, so one
    // early deposit cannot extrapolate a misleadingly-soon finish.
    private function dailyContributionRate(Goal $goal, int $accountId, User $user): float
    {
        $windowStart = CarbonImmutable::today()->subDays(self::TRAILING_WINDOW_DAYS)->toDateString();
        $effectiveStart = $goal->start_date->toDateString() > $windowStart
            ? $goal->start_date->toDateString()
            : $windowStart;

        $elapsedDays = CarbonImmutable::parse($effectiveStart)->diffInDays(CarbonImmutable::today());
        if ($elapsedDays < self::MIN_OBSERVATION_DAYS) {
            return 0.0;
        }

        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->whereIn('type', ['transfer_in', 'income'])
            ->where('posted_at', '>=', $effectiveStart)
            ->get(['amount_minor', 'currency']);

        $windowSum = 0;
        foreach ($rows as $r) {
            $money = Money::ofMinor(self::toInt($r->amount_minor), self::toString($r->currency));
            $windowSum += $this->fx->convertToBase($money, $goal->target_currency)->converted->toMinor();
        }

        return $windowSum / max(1, $elapsedDays);
    }

    // Warms the forecast cache as a sanity signal only — never the source of
    // the contribution figure (a ForecastDto point is the account's balance
    // trajectory, not a goal contribution). A missing/cross-user account falls
    // back to run-rate only defensively.
    private function warmForecastCache(int $accountId, int $daysToFinish, User $user): void
    {
        $horizon = $this->coveringHorizon($daysToFinish);

        try {
            $this->forecast->forUser($accountId, $horizon, null, $user);
        } catch (NotFoundHttpException) {
            // Cross-user or missing account — should not occur given FK +
            // scope, but fall back to run-rate only defensively.
        }
    }

    private function coveringHorizon(int $daysToFinish): int
    {
        return match (true) {
            $daysToFinish <= 30 => 30,
            $daysToFinish <= 60 => 60,
            default => 90,
        };
    }
}
