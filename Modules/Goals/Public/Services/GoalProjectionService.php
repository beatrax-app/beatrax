<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Pots\Public\Services\PotBalanceQuery;

final class GoalProjectionService
{
    private const int TRAILING_WINDOW_DAYS = 90;

    private const int HORIZON_LIMIT_DAYS = 90;

    // Below this, one early deposit would extrapolate a misleadingly-soon
    // finish, so the card says it has too little history instead. Past it, a
    // zero rate means an empty trailing window, which is a different sentence.
    private const int MIN_OBSERVATION_DAYS = 7;

    /** @var array{date: null, beyondHorizon: false, stalled: false} */
    private const array NO_PROJECTION = ['date' => null, 'beyondHorizon' => false, 'stalled' => false];

    /** @var array{date: null, beyondHorizon: false, stalled: true} */
    private const array STALLED = ['date' => null, 'beyondHorizon' => false, 'stalled' => true];

    public function __construct(
        private readonly ExchangeRateService $fx,
        private readonly PotBalanceQuery $potBalance,
    ) {}

    // `stalled` separates the two reasons a rate can be zero, because the card
    // says one of two different things: a goal younger than the observation
    // window has too little history, and an older one with an empty trailing
    // window has plenty of history and nothing recent in it.
    /**
     * @param  array{balance: int, currency: string, potId: int}|null  $linkedPot
     * @param  list<array{amountMinor: int, currency: string, postedAt: string}>  $attributed  every attribution on this goal, whenever it posted
     * @return array{date: ?string, beyondHorizon: bool, stalled: bool}
     */
    public function project(Goal $goal, int $contributedMinor, User $user, ?array $linkedPot, array $attributed): array
    {
        if ($this->hasNoProjection($goal, $contributedMinor)) {
            return self::NO_PROJECTION;
        }

        $dailyRateMinor = $this->dailyContributionRate($goal, $user, $linkedPot, $attributed);
        if ($dailyRateMinor <= 0.0) {
            return self::STALLED;
        }

        $remainingMinor = $goal->target_minor - $contributedMinor;
        $daysToFinish = (int) ceil($remainingMinor / $dailyRateMinor);

        return [
            'date' => CarbonImmutable::today()->addDays($daysToFinish)->format('Y-m-d'),
            'beyondHorizon' => $daysToFinish > self::HORIZON_LIMIT_DAYS,
            'stalled' => false,
        ];
    }

    private function hasNoProjection(Goal $goal, int $contributedMinor): bool
    {
        return $contributedMinor >= $goal->target_minor
            || $this->observedDays($goal) < self::MIN_OBSERVATION_DAYS;
    }

    // Measured against whatever source the goal's progress comes from — pot
    // movements when linked, attributed transactions otherwise — so the rate and
    // the level can never describe different money.
    /**
     * @param  array{balance: int, currency: string, potId: int}|null  $linkedPot
     * @param  list<array{amountMinor: int, currency: string, postedAt: string}>  $attributed
     */
    private function dailyContributionRate(Goal $goal, User $user, ?array $linkedPot, array $attributed): float
    {
        $effectiveStart = $this->effectiveStart($goal);
        $elapsedDays = $this->observedDays($goal);

        $windowSum = $linkedPot !== null
            ? $this->potWindowSum($linkedPot, $goal->target_currency, $effectiveStart, $user)
            : $this->attributedWindowSum($goal, $effectiveStart, $attributed);

        return $windowSum / max(1, $elapsedDays);
    }

    // The trailing window, clipped to the goal's own start: a goal created
    // today would otherwise divide one early deposit by 90 days.
    private function effectiveStart(Goal $goal): string
    {
        $windowStart = CarbonImmutable::today()->subDays(self::TRAILING_WINDOW_DAYS)->toDateString();

        return $goal->start_date->toDateString() > $windowStart
            ? $goal->start_date->toDateString()
            : $windowStart;
    }

    private function observedDays(Goal $goal): int
    {
        return (int) CarbonImmutable::parse($this->effectiveStart($goal))->diffInDays(CarbonImmutable::today());
    }

    /**
     * @param  array{balance: int, currency: string, potId: int}  $linkedPot
     */
    private function potWindowSum(array $linkedPot, string $targetCurrency, string $since, User $user): int
    {
        $minor = $this->potBalance->netMovementForPotSince($linkedPot['potId'], $since, $user);

        if ($minor === 0 || $linkedPot['currency'] === '' || $linkedPot['currency'] === $targetCurrency) {
            return $minor;
        }

        return $this->convert($minor, $linkedPot['currency'], $targetCurrency);
    }

    // The caller has already read every attribution on this goal to total it,
    // so the window is a filter over those rather than a second statement per
    // goal. `posted_at` is a date column and the bound is a date string, which
    // compare the same way here as they did in SQL.
    /**
     * @param  list<array{amountMinor: int, currency: string, postedAt: string}>  $attributed
     */
    private function attributedWindowSum(Goal $goal, string $since, array $attributed): int
    {
        $sum = 0;
        foreach ($attributed as $contribution) {
            if ($contribution['postedAt'] < $since) {
                continue;
            }

            $sum += $this->convert($contribution['amountMinor'], $contribution['currency'], $goal->target_currency);
        }

        return $sum;
    }

    private function convert(int $minor, string $from, string $to): int
    {
        return $this->fx->convertToBase(Money::ofMinor($minor, $from), $to)->converted->toMinor();
    }
}
