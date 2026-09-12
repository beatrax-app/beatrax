<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Carbon\CarbonImmutable;
use Modules\FX\Public\Dto\RateSet;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Goals\Models\Goal;

final readonly class GoalProjectionService
{
    private const int TRAILING_WINDOW_DAYS = 90;

    private const int HORIZON_LIMIT_DAYS = 90;

    private const int MAX_DATE_DAYS = 36_500;

    // Below this, one early deposit would extrapolate a misleadingly-soon
    // finish, so the card says it has too little history instead. Past it, a
    // zero rate means an empty trailing window, which is a different sentence.
    private const int MIN_OBSERVATION_DAYS = 7;

    /** @var array{date: null, beyondHorizon: false, stalled: false} */
    private const array NO_PROJECTION = ['date' => null, 'beyondHorizon' => false, 'stalled' => false];

    /** @var array{date: null, beyondHorizon: false, stalled: true} */
    private const array STALLED = ['date' => null, 'beyondHorizon' => false, 'stalled' => true];

    // A rate of a few cents a day answers a finish past PHP_INT_MAX. Clamping
    // it to a century printed a date the arithmetic never produced and labelled
    // it an estimate; no date at all, flagged beyond the horizon, is the only
    // honest answer the caller can render.
    /** @var array{date: null, beyondHorizon: true, stalled: false} */
    private const array BEYOND_DATING = ['date' => null, 'beyondHorizon' => true, 'stalled' => false];

    public function __construct(private CrossCurrencyTotal $fx) {}

    // `stalled` separates the two reasons a rate can be zero, because the card
    // says one of two different things: a goal younger than the observation
    // window has too little history, and an older one with an empty trailing
    // window has plenty of history and nothing recent in it.
    /**
     * @param  array{balance: int, currency: string, potId: int, hasMovements?: bool, movementsByDay: array<string, int>}|null  $linkedPot
     * @param  list<array{amountMinor: int, currency: string, postedAt: string}>  $attributed  every attribution on this goal, whenever it posted
     * @param  CarbonImmutable  $today  read once by the caller, so a render straddling midnight cannot mix two days
     * @return array{date: ?string, beyondHorizon: bool, stalled: bool}
     */
    public function project(Goal $goal, int $contributedMinor, ?array $linkedPot, array $attributed, RateSet $rates, CarbonImmutable $today): array
    {
        if ($this->hasNoProjection($goal, $contributedMinor, $today)) {
            return self::NO_PROJECTION;
        }

        $dailyRateMinor = $this->dailyContributionRate($goal, $linkedPot, $attributed, $rates, $today);
        if ($dailyRateMinor <= 0.0) {
            return self::STALLED;
        }

        $remainingMinor = $goal->target_minor - $contributedMinor;
        $daysToFinish = ceil($remainingMinor / $dailyRateMinor);

        return $daysToFinish > (float) self::MAX_DATE_DAYS
            ? self::BEYOND_DATING
            : [
                'date' => $today->addDays((int) $daysToFinish)->format('Y-m-d'),
                'beyondHorizon' => $daysToFinish > (float) self::HORIZON_LIMIT_DAYS,
                'stalled' => false,
            ];
    }

    private function hasNoProjection(Goal $goal, int $contributedMinor, CarbonImmutable $today): bool
    {
        return $contributedMinor >= $goal->target_minor
            || $this->observedDays($goal, $today) < self::MIN_OBSERVATION_DAYS;
    }

    // Measured against whatever source the goal's progress comes from — pot
    // movements when linked, attributed transactions otherwise — so the rate and
    // the level can never describe different money.
    /**
     * @param  array{balance: int, currency: string, potId: int, hasMovements?: bool, movementsByDay: array<string, int>}|null  $linkedPot
     * @param  list<array{amountMinor: int, currency: string, postedAt: string}>  $attributed
     */
    private function dailyContributionRate(Goal $goal, ?array $linkedPot, array $attributed, RateSet $rates, CarbonImmutable $today): float
    {
        $effectiveStart = $this->effectiveStart($goal, $today);
        $elapsedDays = $this->observedDays($goal, $today);

        $windowSum = $linkedPot !== null
            ? $this->potWindowSum($linkedPot, $goal->target_currency, $effectiveStart, $rates)
            : $this->attributedWindowSum($goal, $effectiveStart, $attributed, $rates);

        return $windowSum / max(1, $elapsedDays);
    }

    // The trailing window, clipped to the goal's own start: a goal created
    // today would otherwise divide one early deposit by 90 days.
    private function effectiveStart(Goal $goal, CarbonImmutable $today): string
    {
        $windowStart = $this->trailingWindowStart($today);

        return $goal->start_date->toDateString() > $windowStart
            ? $goal->start_date->toDateString()
            : $windowStart;
    }

    // The widest window any goal's rate is measured over, so a caller loading a
    // list can read every linked pot's movements once and hand each goal the
    // days its own start has not clipped away.
    public function trailingWindowStart(CarbonImmutable $today): string
    {
        return $today->subDays(self::TRAILING_WINDOW_DAYS)->toDateString();
    }

    private function observedDays(Goal $goal, CarbonImmutable $today): int
    {
        return (int) CarbonImmutable::parse($this->effectiveStart($goal, $today))->diffInDays($today);
    }

    // The caller has already read every linked pot's movements over the widest
    // window any goal uses, so this goal's window is a filter over those rather
    // than a second statement per card. The day buckets and the bound are both
    // date strings, which compare the same way here as they did in SQL.
    /**
     * @param  array{balance: int, currency: string, potId: int, hasMovements?: bool, movementsByDay: array<string, int>}  $linkedPot
     */
    private function potWindowSum(array $linkedPot, string $targetCurrency, string $since, RateSet $rates): int
    {
        $minor = 0;
        foreach ($linkedPot['movementsByDay'] as $day => $dayMinor) {
            if ($day >= $since) {
                $minor += $dayMinor;
            }
        }

        if ($minor === 0 || $linkedPot['currency'] === '') {
            return $minor;
        }

        return $this->fx->withRates([$linkedPot['currency'] => $minor], $targetCurrency, $rates)->minor;
    }

    // The caller has already read every attribution on this goal to total it,
    // so the window is a filter over those rather than a second statement per
    // goal. `posted_at` is a date column and the bound is a date string, which
    // compare the same way here as they did in SQL.
    /**
     * @param  list<array{amountMinor: int, currency: string, postedAt: string}>  $attributed
     */
    private function attributedWindowSum(Goal $goal, string $since, array $attributed, RateSet $rates): int
    {
        $byCurrency = [];
        foreach ($attributed as $contribution) {
            if ($contribution['postedAt'] < $since) {
                continue;
            }

            $byCurrency[$contribution['currency']]
                = ($byCurrency[$contribution['currency']] ?? 0) + $contribution['amountMinor'];
        }

        return $this->fx->withRates($byCurrency, $goal->target_currency, $rates)->minor;
    }
}
