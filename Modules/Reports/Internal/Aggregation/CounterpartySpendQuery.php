<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Public\Dto\ReportResultRow;
use stdClass;

/**
 * Counterparty-dimension aggregation for the Reports builder's spend/
 * income/net metrics (Req 2/3) — a thin, single-table `GROUP BY` over the
 * `transactions` parent rows.
 *
 * No split-leg join is needed here (unlike `CategorySpendQuery`):
 * `transaction_splits` carries only `category_id`, never `account_id` or
 * `counterparty_id` — a split divides a transaction's spend across
 * *categories*, never across counterparties. A split parent's
 * `settled_amount_minor` already equals the sum of its legs and every leg
 * shares the parent's counterparty, so aggregating the parent rows directly
 * is exact (999.6-RESEARCH.md Pattern 2 "split-leg insight").
 *
 * Mirrors `ThisPeriodAtAGlanceQuery::incomeForPeriod()`'s type-based
 * single-table shape, generalized to spend/income/net and grouped by
 * `counterparty_id` instead of summed to a scalar.
 */
final class CounterpartySpendQuery
{
    /**
     * In-memory sentinel for the "no counterparty" bucket — a real
     * counterparty id is always a positive autoincrement value.
     */
    private const NO_COUNTERPARTY_SENTINEL = -1;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CounterpartyProfileQuery $counterpartyProfileQuery,
    ) {}

    /**
     * @param  string  $metric  'spend' | 'income' | 'net'
     * @return list<ReportResultRow>
     */
    public function forUserAndPeriod(User $user, Period $period, string $metric, string $currency): array
    {
        $rows = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('type', self::metricTypes($metric))
            ->where('settled_currency', $currency)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->groupBy('counterparty_id')
            ->selectRaw('counterparty_id, '.self::amountExpr($metric).' AS amount_minor')
            ->get();

        /** @var array<int, int> $map */
        $map = [];
        $counterpartyIds = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $counterpartyId = $row->counterparty_id === null ? null : self::toInt($row->counterparty_id);
            $key = $counterpartyId ?? self::NO_COUNTERPARTY_SENTINEL;
            $map[$key] = ($map[$key] ?? 0) + self::toInt($row->amount_minor);
            if ($counterpartyId !== null) {
                $counterpartyIds[] = $counterpartyId;
            }
        }

        $identities = $counterpartyIds === [] ? [] : $this->counterpartyProfileQuery->identitiesForIds($user, $counterpartyIds);

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

    /**
     * @return list<string>
     */
    private static function metricTypes(string $metric): array
    {
        return match ($metric) {
            'spend' => ['expense'],
            'income' => ['income'],
            'net' => ['expense', 'income'],
            default => throw new InvalidArgumentException("Unknown report metric: {$metric}"),
        };
    }

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

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
