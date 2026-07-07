<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Public\Dto\ReportResultRow;
use stdClass;

/**
 * Account-dimension aggregation for the Reports builder's spend/income/net
 * metrics (Req 2/3) — a thin, single-table `GROUP BY` over the
 * `transactions` parent rows.
 *
 * `transactions.account_id` is a required (non-nullable) FK, and, like
 * counterparty, is invariant across a split parent's legs — no split-leg
 * join is needed (999.6-RESEARCH.md Pattern 2 "split-leg insight",
 * mirrors `CounterpartySpendQuery`).
 */
final class AccountSpendQuery
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @param  string  $metric  'spend' | 'income' | 'net'
     * @param  list<int>  $accountIds  T-999.6-06/14: restrict to these account ids (empty = no restriction). Applied ALONGSIDE the existing `where('user_id', ...)` guard below, so a foreign id can only ever narrow this user's own result to nothing — never widen it to another user's rows.
     * @param  list<int>  $categoryIds  restrict to these category ids (empty = no restriction)
     * @param  list<int>  $counterpartyIds  restrict to these counterparty ids (empty = no restriction)
     * @param  ?int  $amountMinMinor  restrict to rows whose ABS(settled_amount_minor) >= this (empty = no restriction)
     * @param  ?int  $amountMaxMinor  restrict to rows whose ABS(settled_amount_minor) <= this (empty = no restriction)
     * @param  string  $amountDirection  'in' | 'out' | 'both' — restricts to settled_amount_minor > 0 / < 0 / no restriction
     * @return list<ReportResultRow>
     */
    public function forUserAndPeriod(
        User $user,
        Period $period,
        string $metric,
        string $currency,
        array $accountIds = [],
        array $categoryIds = [],
        array $counterpartyIds = [],
        ?int $amountMinMinor = null,
        ?int $amountMaxMinor = null,
        string $amountDirection = 'both',
    ): array {
        $connection = $this->db->connection();

        $rows = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('type', self::metricTypes($metric))
            ->where('settled_currency', $currency)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->when($accountIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('account_id', $accountIds))
            ->when($categoryIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('category_id', $categoryIds))
            ->when($counterpartyIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('counterparty_id', $counterpartyIds))
            ->when($amountMinMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(settled_amount_minor) >= ?', [$amountMinMinor]))
            ->when($amountMaxMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(settled_amount_minor) <= ?', [$amountMaxMinor]))
            ->when($amountDirection === 'in', static fn (QueryBuilder $q): QueryBuilder => $q->where('settled_amount_minor', '>', 0))
            ->when($amountDirection === 'out', static fn (QueryBuilder $q): QueryBuilder => $q->where('settled_amount_minor', '<', 0))
            ->groupBy('account_id')
            ->selectRaw('account_id, '.self::amountExpr($metric).' AS amount_minor')
            ->get();

        /** @var array<int, int> $map */
        $map = [];
        $resultAccountIds = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $accountId = self::toInt($row->account_id);
            $map[$accountId] = ($map[$accountId] ?? 0) + self::toInt($row->amount_minor);
            $resultAccountIds[] = $accountId;
        }

        $labels = $resultAccountIds === [] ? [] : $this->loadAccountLabels($resultAccountIds, $user->id);

        $result = [];
        foreach ($map as $accountId => $amountMinor) {
            $result[] = new ReportResultRow(
                groupKey: $accountId,
                groupLabel: $labels[$accountId] ?? 'Unknown account',
                amountMinor: $amountMinor,
                currency: $currency,
            );
        }

        return $result;
    }

    /**
     * @param  list<int>  $accountIds
     * @return array<int, string>
     */
    private function loadAccountLabels(array $accountIds, int $userId): array
    {
        $rows = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $userId)
            ->whereIn('id', $accountIds)
            ->get(['id', 'name']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $map[self::toInt($row->id)] = self::toStr($row->name);
        }

        return $map;
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

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
