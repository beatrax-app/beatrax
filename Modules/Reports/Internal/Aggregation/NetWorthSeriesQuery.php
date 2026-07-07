<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Reports\Internal\Aggregation\Dto\NetWorthSeriesPoint;
use stdClass;

/**
 * Net-worth-over-time as an on-demand SAMPLED series (Req 2/5/7) — no table
 * stores historical net worth; every point is computed fresh by repeating
 * `NetWorthQuery::forUser()`'s (Modules\Forecasting\Public\Services\
 * NetWorthQuery) exclude+count algorithm once per `TimeBucketGenerator`
 * sample date instead of once for "today" (999.6-RESEARCH.md Pattern 3).
 * This is a time series (no group-by dimension): exactly one point per
 * generated bucket.
 *
 * SCOPE LIMITATION (Open Question, RESEARCH Pattern 3 / Pitfall 9): the
 * most-recent point in this series is NOT guaranteed to be byte-identical
 * to the dashboard net-worth card's (`NetWorthQuery`) "today" figure.
 * `NetWorthQuery` sources its "today" balance from
 * `Forecasting\Internal\Pipeline\BalanceAnchorResolver`, which is
 * arch-fenced (`Forecasting\Internal` is off-limits to every other module,
 * `tests/Contracts/BoundaryArchTest.php`) and may apply a richer
 * statement-anchor + delta strategy than a raw sum of cleared transactions.
 * This class instead uses `AccountBalanceQuery::clearedBalanceAsOf()`
 * (Ledger `Public`) for every sample point — the only Public, point-in-
 * time-capable balance query. Req 5's testable guarantee (unconvertible
 * accounts excluded + counted, never a silent 1:1 fallback) still holds
 * exactly; only byte-parity with "today" is out of scope for this class.
 *
 * Excluded-kind parity: `paypal_funding` accounts are excluded from every
 * point exactly like `NetWorthQuery::EXCLUDED_KINDS`, so the series matches
 * the dashboard net-worth card's account set for every reason OTHER than
 * FX exclusion.
 *
 * Sample date: each bucket is a half-open `[start, endExclusive)` `Period`
 * (`TimeBucketGenerator`); the point is sampled at the last actual day
 * inside that window (`endExclusive->subDay()`) — e.g. a "Jul 2025" bucket
 * `[2025-07-01, 2025-08-01)` samples the end-of-month balance on
 * 2025-07-31.
 */
final class NetWorthSeriesQuery
{
    /** Must match NetWorthQuery::EXCLUDED_KINDS exactly (RESEARCH Pattern 3). */
    private const EXCLUDED_KINDS = ['paypal_funding'];

    public function __construct(
        private readonly AccountBalanceQuery $accountBalanceQuery,
        private readonly ExchangeRateService $fx,
        private readonly DatabaseManager $db,
        private readonly TimeBucketGenerator $timeBucketGenerator,
    ) {}

    /**
     * @param  string  $granularity  'monthly' | 'weekly'
     * @return list<NetWorthSeriesPoint>
     */
    public function forUser(User $user, Period $period, string $granularity = 'monthly'): array
    {
        $buckets = $this->timeBucketGenerator->generate($period, $granularity);
        $baseCurrency = $user->base_currency;

        /** @var Collection<int, stdClass> $accounts */
        $accounts = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->whereNotIn('kind', self::EXCLUDED_KINDS)
            ->orderBy('id')
            ->get(['id', 'default_currency']);

        $points = [];
        foreach ($buckets as $bucket) {
            $asOf = $bucket->endExclusive->subDay();

            [$totalMinor, $excludedCount] = $this->sampleAt($user, $accounts, $asOf, $baseCurrency);

            $points[] = new NetWorthSeriesPoint(
                date: $asOf,
                label: $bucket->label,
                totalMinor: $totalMinor,
                currency: $baseCurrency,
                excludedCount: $excludedCount,
            );
        }

        return $points;
    }

    /**
     * @param  Collection<int, stdClass>  $accounts
     * @return array{0: int, 1: int}
     */
    private function sampleAt(User $user, Collection $accounts, CarbonImmutable $asOf, string $baseCurrency): array
    {
        $total = 0;
        $excludedCount = 0;

        foreach ($accounts as $account) {
            $accountId = self::toInt($account->id);
            $currency = self::toStr($account->default_currency);

            $balanceMinor = $this->accountBalanceQuery->clearedBalanceAsOf($accountId, $user, $asOf);
            $money = Money::ofMinor($balanceMinor, $currency);
            $result = $this->fx->convertAtDate($money, $baseCurrency, $asOf->toDateString());

            // Never a silent 1:1 fallback (T-999.6-12): when no rate is
            // available, ExchangeRateService returns a passthrough carrying
            // the ORIGINAL currency (the currency === target fast path
            // already handled same-currency accounts above). Any mismatch
            // here means "excluded", never "assume rate 1".
            if ($result->converted->currency() !== $baseCurrency) {
                $excludedCount++;

                continue;
            }

            $total += $result->converted->toMinor();
        }

        return [$total, $excludedCount];
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
