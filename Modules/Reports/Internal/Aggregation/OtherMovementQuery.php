<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Dto\Period;
use stdClass;

// Every metric is defined over `expense` and `income` alone, so a bank fee and
// a manual adjustment appear in no report: 9,00 missing from the demo month's
// 2.468,11. This sums what the metric leaves out so the page can say it.
final class OtherMovementQuery
{
    use CoercesScalars;

    private const TYPES = ['fee', 'adjustment'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SpendFilterApplier $filterApplier,
    ) {}

    // Grouped, not summed: base mode converts each and adds, original mode keeps
    // only the currency it headlines.
    /**
     * @return array<string, int> settled currency => total, signed the same way $metric signs its own rows
     */
    public function totalsByCurrency(User $user, Period $period, string $metric, SpendQueryFilters $filters): array
    {
        $connection = $this->db->connection();

        $rows = $connection->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('type', self::TYPES)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->whereNotNull('settled_currency')
            ->tap(fn (QueryBuilder $q): QueryBuilder => $this->filterApplier->apply($q, $filters))
            ->groupBy('settled_currency')
            ->get(['settled_currency', $connection->raw(ReportMetric::fromMetric($metric)->sumExpr().' AS amount_minor')]);

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
