<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\FX\Public\Dto\RateSet;
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

final readonly class NetWorthSeriesQuery
{
    use CoercesScalars;

    public function __construct(
        private AccountBalanceQuery $accountBalanceQuery,
        private ExchangeRateService $fx,
        private DatabaseManager $db,
        private TimeBucketGenerator $timeBucketGenerator,
        private BaseCurrency $baseCurrency,
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

        // The same set the dashboard card reads, asked of the same enum rather
        // than of a copy of its list: the series is the card plotted over time,
        // so a kind either surface counts alone is a step with no cause.
        /** @var Collection<int, stdClass> $accounts */
        $accounts = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->whereNotIn('kind', AccountKind::mirrorValues())
            ->when($filters->accountIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('id', $filters->accountIds))
            ->orderBy('id')
            ->get(['id', 'name']);

        $points = [];
        foreach ($buckets as $bucket) {
            $asOf = $bucket->endExclusive->subDay();

            $sample = $this->sampleAt($user, $accounts, $asOf, $baseCurrency);

            $points[] = new NetWorthSeriesPoint(
                date: $asOf,
                label: $bucket->label,
                totalMinor: $sample['total'],
                currency: $baseCurrency,
                rates: $sample['rates'],
                excludedAccounts: $sample['excluded'],
            );
        }

        return $points;
    }

    /**
     * @param  Collection<int, stdClass>  $accounts
     * @return array{total: int, excluded: array<int, string>, rates: RateSet}
     */
    private function sampleAt(User $user, Collection $accounts, CarbonImmutable $asOf, string $baseCurrency): array
    {
        $total = 0;
        /** @var array<int, string> $excluded */
        $excluded = [];
        // Each bucket prices at the rate in effect on its OWN day, so the set
        // belongs to the bucket and not to the series: sixty of these are
        // sixty conversions, each answering for its own figure.
        $rates = RateSet::empty($baseCurrency);

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
                    // Keyed by account so an account holding two unconvertible
                    // currencies is still one account the reader is told about,
                    // and carrying the name because the reader is told WHICH.
                    $excluded[$accountId] = self::toString($account->name);

                    continue;
                }

                $total += $result->converted->toMinor();
                // Two accounts holding one currency on one day converted at one
                // rate, so the set collapses them.
                $rates = $rates->withConversion($result);
            }
        }

        return ['total' => $total, 'excluded' => $excluded, 'rates' => $rates];
    }
}
