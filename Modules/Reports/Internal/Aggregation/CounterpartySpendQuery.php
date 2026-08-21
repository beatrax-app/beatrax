<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Dto\ReportResultRow;
use stdClass;

final class CounterpartySpendQuery
{
    use CoercesScalars;

    private const NO_COUNTERPARTY_SENTINEL = -1;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CounterpartyProfileQuery $counterpartyProfileQuery,
        private readonly SpendFilterApplier $filterApplier,
    ) {}

    /**
     * @param  string  $metric  'spend' | 'income' | 'net'
     * @return list<ReportResultRow>
     */
    public function forUserAndPeriod(
        User $user,
        Period $period,
        string $metric,
        string $currency,
        SpendQueryFilters $filters = new SpendQueryFilters,
    ): array {
        $reportMetric = ReportMetric::fromMetric($metric);

        $rows = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('type', $reportMetric->types())
            ->where('settled_currency', $currency)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->tap(fn (QueryBuilder $q): QueryBuilder => $this->filterApplier->apply($q, $filters))
            ->groupBy('counterparty_id')
            ->selectRaw('counterparty_id, '.$reportMetric->sumExpr().' AS amount_minor')
            ->get();

        /** @var array<int, int> $map */
        $map = [];
        $resultCounterpartyIds = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $counterpartyId = $row->counterparty_id === null ? null : self::toInt($row->counterparty_id);
            $key = $counterpartyId ?? self::NO_COUNTERPARTY_SENTINEL;
            $map[$key] = ($map[$key] ?? 0) + self::toInt($row->amount_minor);
            if ($counterpartyId !== null) {
                $resultCounterpartyIds[] = $counterpartyId;
            }
        }

        $identities = $resultCounterpartyIds === [] ? [] : $this->counterpartyProfileQuery->identitiesForIds($user, $resultCounterpartyIds);

        $result = [];
        foreach ($map as $key => $amountMinor) {
            if ($key === self::NO_COUNTERPARTY_SENTINEL) {
                $result[] = new ReportResultRow(groupKey: null, groupLabel: 'No counterparty', amountMinor: $amountMinor, currency: $currency);

                continue;
            }

            $label = $identities[$key]['displayName'] ?? 'Unknown counterparty';
            $result[] = new ReportResultRow(groupKey: $key, groupLabel: $label, amountMinor: $amountMinor, currency: $currency);
        }

        return $result;
    }
}
