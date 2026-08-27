<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\FX\Public\Enums\ConversionOutcome;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\Services\BaseCurrency;
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
        private readonly BaseCurrency $baseCurrency,
    ) {}

    // Only the account filter is honoured. Net worth is a balance, not a set of
    // transactions, so a category/counterparty/amount predicate has nothing to
    // select on -- the builder hides those controls for this metric and says so
    // when a URL still carries them, rather than dropping them in silence.
    /**
     * @return list<NetWorthSeriesPoint>
     */
    public function forUser(User $user, Period $period, ?ReportGranularity $granularity = null, SpendQueryFilters $filters = new SpendQueryFilters): array
    {
        $buckets = $this->timeBucketGenerator->generate($period, $granularity ?? ReportGranularity::default());
        $baseCurrency = $this->baseCurrency->forUser($user);

        /** @var Collection<int, stdClass> $accounts */
        $accounts = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->whereNotIn('kind', self::EXCLUDED_KINDS)
            ->when($filters->accountIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('id', $filters->accountIds))
            ->orderBy('id')
            ->get(['id']);

        $points = [];
        foreach ($buckets as $bucket) {
            $asOf = $bucket->endExclusive->subDay();

            [$totalMinor, $excludedAccountIds] = $this->sampleAt($user, $accounts, $asOf, $baseCurrency);

            $points[] = new NetWorthSeriesPoint(
                date: $asOf,
                label: $bucket->label,
                totalMinor: $totalMinor,
                currency: $baseCurrency,
                excludedAccountIds: $excludedAccountIds,
            );
        }

        return $points;
    }

    /**
     * @param  Collection<int, stdClass>  $accounts
     * @return array{0: int, 1: list<int>}
     */
    private function sampleAt(User $user, Collection $accounts, CarbonImmutable $asOf, string $baseCurrency): array
    {
        $total = 0;
        /** @var array<int, true> $excluded */
        $excluded = [];

        foreach ($accounts as $account) {
            $accountId = self::toInt($account->id);
            $balance = $this->accountBalanceQuery->clearedBalanceAsOf($accountId, $user, $asOf);

            // One account can hold several currencies, so each line is
            // converted at its own rate rather than the account being credited
            // with one currency it happens to be labelled with.
            foreach ($balance->lines() as $currency => $balanceMinor) {
                $money = Money::ofMinor($balanceMinor, $currency);
                $result = $this->fx->convertAtDate($money, $baseCurrency, $asOf->toDateString());

                // Never a silent 1:1 fallback. Asked of the outcome, not of the
                // currency: a line already in the base currency is a Passthrough
                // and still belongs in the total, so only NoRate is an exclusion.
                if ($result->outcome === ConversionOutcome::NoRate) {
                    // A set: an account holding two unconvertible currencies is
                    // still one account the reader has to be told about.
                    $excluded[$accountId] = true;

                    continue;
                }

                $total += $result->converted->toMinor();
            }
        }

        return [$total, array_keys($excluded)];
    }
}
