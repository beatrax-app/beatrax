<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Dto\Period;
use stdClass;

// A bank fee, a manual adjustment and -- for a metric that does not already
// count it -- a refund appear in no report: 9,00 missing from the demo month's
// 2.468,11. This sums what the metric leaves out so the page can say it.
final readonly class OtherMovementQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private SpendFilterApplier $filterApplier,
    ) {}

    // Grouped, not summed: base mode converts each and adds, original mode
    // reports every currency, since it converts none of them.
    /**
     * @param  SpendQueryFilters  $filters  bounds as the reader typed them, in their own currency
     * @param  callable(string $currency): ?SpendQueryFilters  $boundsForCurrency  the same set restated in one settled currency, or null where no rate reaches it
     * @return array<string, int> settled currency => total, signed the same way $metric signs its own rows
     */
    public function totalsByCurrency(User $user, Period $period, string $metric, SpendQueryFilters $filters, callable $boundsForCurrency): array
    {
        // Without a bound the whole disclosure is one grouped query, which is
        // what it costs on every report that sets no amount filter.
        $totals = $this->sumByCurrency($user, $period, $metric, $filters->withoutAmountBounds());

        if (! $filters->hasAmountBounds()) {
            return $totals;
        }

        // With one, the threshold is a different number in each currency, so
        // each currency's own bucket is re-summed under its own bound.
        $bounded = [];
        foreach (array_keys($totals) as $currency) {
            $scoped = $boundsForCurrency($currency);
            if ($scoped === null) {
                continue;
            }

            $bounded += $this->sumByCurrency($user, $period, $metric, $scoped, $currency);
        }

        return $bounded;
    }

    /**
     * @return array<string, int>
     */
    private function sumByCurrency(User $user, Period $period, string $metric, SpendQueryFilters $filters, ?string $onlyCurrency = null): array
    {
        $connection = $this->db->connection();

        $reportMetric = ReportMetric::fromMetric($metric);

        $rows = $connection->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('type', $reportMetric->disclosedTypes())
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->whereNotNull('settled_currency')
            ->when($onlyCurrency !== null, static fn (QueryBuilder $q): QueryBuilder => $q->where('settled_currency', $onlyCurrency))
            ->tap(fn (QueryBuilder $q): QueryBuilder => $this->filterApplier->apply($q, $filters))
            ->groupBy('settled_currency')
            ->get(['settled_currency', $connection->raw($reportMetric->sumExpr().' AS amount_minor')]);

        $totals = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $currency = self::toString($row->settled_currency);
            if ($currency === '') {
                continue;
            }
            $totals[$currency] = ($totals[$currency] ?? 0) + self::toInt($row->amount_minor);
        }

        return $totals;
    }
}
