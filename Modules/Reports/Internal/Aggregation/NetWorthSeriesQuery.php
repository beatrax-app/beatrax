<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Reports\Internal\Aggregation\Dto\NetWorthSeriesPoint;
use Modules\Reports\Internal\Enums\ReportGranularity;
use stdClass;

final class NetWorthSeriesQuery
{
    use CoercesScalars;

    // Must match Forecasting's NetWorthQuery::EXCLUDED_KINDS exactly, so
    // this series' account set stays consistent with the dashboard card.
    private const EXCLUDED_KINDS = [AccountKind::PaypalFunding->value];

    public function __construct(
        private readonly AccountBalanceQuery $accountBalanceQuery,
        private readonly ExchangeRateService $fx,
        private readonly DatabaseManager $db,
        private readonly TimeBucketGenerator $timeBucketGenerator,
    ) {}

    /**
     * @return list<NetWorthSeriesPoint>
     */
    public function forUser(User $user, Period $period, ?ReportGranularity $granularity = null): array
    {
        $buckets = $this->timeBucketGenerator->generate($period, $granularity ?? ReportGranularity::default());
        $baseCurrency = $user->base_currency;

        /** @var Collection<int, stdClass> $accounts */
        $accounts = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->whereNotIn('kind', self::EXCLUDED_KINDS)
            ->orderBy('id')
            ->get(['id']);

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
            $balance = $this->accountBalanceQuery->clearedBalanceAsOf($accountId, $user, $asOf);

            // One account can hold several currencies, so each line is
            // converted at its own rate rather than the account being credited
            // with one currency it happens to be labelled with.
            foreach ($balance->lines() as $currency => $balanceMinor) {
                $money = Money::ofMinor($balanceMinor, $currency);
                $result = $this->fx->convertAtDate($money, $baseCurrency, $asOf->toDateString());

                // Never a silent 1:1 fallback: with no rate available the service
                // returns a passthrough in the original currency, so a mismatch
                // here means excluded, not rate 1.
                if ($result->converted->currency() !== $baseCurrency) {
                    $excludedCount++;

                    continue;
                }

                $total += $result->converted->toMinor();
            }
        }

        return [$total, $excludedCount];
    }
}
