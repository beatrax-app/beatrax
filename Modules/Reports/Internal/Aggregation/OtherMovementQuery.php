<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Dto\Period;
use stdClass;

// Every report metric is defined over `expense` and `income` alone, so a bank
// fee and a manual adjustment appear in no report at all — on the demo month,
// 9,00 of movement missing from a 2.468,11 total with nothing on the page
// saying so. This sums what the metric leaves out so the page can say it.
final class OtherMovementQuery
{
    use CoercesScalars;

    private const TYPES = ['fee', 'adjustment'];

    public function __construct(private readonly DatabaseManager $db) {}

    // Grouped by currency rather than summed, because the caller decides what
    // a mixed-currency report means: base mode converts each and adds them,
    // original mode keeps the one currency it decided to headline.
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
            ->when($filters->accountIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('account_id', $filters->accountIds))
            ->when($filters->categoryIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('category_id', $filters->categoryIds))
            ->when($filters->counterpartyIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('counterparty_id', $filters->counterpartyIds))
            ->when($filters->amountMinMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(settled_amount_minor) >= ?', [$filters->amountMinMinor]))
            ->when($filters->amountMaxMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(settled_amount_minor) <= ?', [$filters->amountMaxMinor]))
            ->when($filters->amountDirection === 'in', static fn (QueryBuilder $q): QueryBuilder => $q->where('settled_amount_minor', '>', 0))
            ->when($filters->amountDirection === 'out', static fn (QueryBuilder $q): QueryBuilder => $q->where('settled_amount_minor', '<', 0))
            ->groupBy('settled_currency')
            ->get(['settled_currency', $connection->raw(self::amountExpr($metric).' AS amount_minor')]);

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

    // Mirrors the dimension queries: a spend report reads positive, so the
    // fee that sits beside its total has to read positive too.
    /**
     * @return literal-string
     */
    private static function amountExpr(string $metric): string
    {
        return match ($metric) {
            'spend' => 'SUM(-settled_amount_minor)',
            'income', 'net' => 'SUM(settled_amount_minor)',
            default => throw new InvalidArgumentException("Unknown report metric: {$metric}"),
        };
    }
}
