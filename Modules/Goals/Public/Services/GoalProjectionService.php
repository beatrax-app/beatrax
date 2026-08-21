<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Pots\Public\Services\PotBalanceQuery;

final class GoalProjectionService
{
    use CoercesScalars;

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
        private readonly DatabaseManager $db,
        private readonly ExchangeRateService $fx,
        private readonly PotBalanceQuery $potBalance,
    ) {}

    // `stalled` separates the two reasons a rate can be zero, because the card
    // says one of two different things: a goal younger than the observation
    // window has too little history, and an older one with an empty trailing
    // window has plenty of history and nothing recent in it.
    /**
     * @param  array{balance: int, currency: string, potId: int}|null  $linkedPot
     * @return array{date: ?string, beyondHorizon: bool, stalled: bool}
     */
    public function project(Goal $goal, int $contributedMinor, User $user, ?array $linkedPot = null): array
    {
        if ($contributedMinor >= $goal->target_minor) {
            return self::NO_PROJECTION;
        }

        if ($this->observedDays($goal) < self::MIN_OBSERVATION_DAYS) {
            return self::NO_PROJECTION;
        }

        $dailyRateMinor = $this->dailyContributionRate($goal, $user, $linkedPot);
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

    // Measured against whatever source the goal's progress comes from — pot
    // movements when linked, attributed transactions otherwise — so the rate and
    // the level can never describe different money.
    /**
     * @param  array{balance: int, currency: string, potId: int}|null  $linkedPot
     */
    private function dailyContributionRate(Goal $goal, User $user, ?array $linkedPot): float
    {
        $effectiveStart = $this->effectiveStart($goal);
        $elapsedDays = $this->observedDays($goal);

        $windowSum = $linkedPot !== null
            ? $this->potWindowSum($linkedPot, $goal->target_currency, $effectiveStart, $user)
            : $this->attributedWindowSum($goal, $effectiveStart, $user);

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

    private function attributedWindowSum(Goal $goal, string $since, User $user): int
    {
        $rows = $this->db->connection()->table('goal_contributions')
            ->join('transactions', 'goal_contributions.transaction_id', '=', 'transactions.id')
            ->where('goal_contributions.user_id', $user->id)
            ->where('goal_contributions.goal_id', $goal->id)
            ->where('transactions.posted_at', '>=', $since)
            ->get(['transactions.amount_minor', 'transactions.currency']);

        $sum = 0;
        foreach ($rows as $row) {
            $sum += $this->convert(
                self::toInt($row->amount_minor),
                self::toString($row->currency),
                $goal->target_currency,
            );
        }

        return $sum;
    }

    private function convert(int $minor, string $from, string $to): int
    {
        return $this->fx->convertToBase(Money::ofMinor($minor, $from), $to)->converted->toMinor();
    }
}
