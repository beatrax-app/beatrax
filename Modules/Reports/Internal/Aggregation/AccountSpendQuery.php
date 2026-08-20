<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Public\Dto\ReportResultRow;
use stdClass;

final class AccountSpendQuery
{
    use CoercesScalars;

    public function __construct(
        private readonly DatabaseManager $db,
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
        $connection = $this->db->connection();

        $rows = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('type', self::metricTypes($metric))
            ->where('settled_currency', $currency)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->tap(fn (QueryBuilder $q): QueryBuilder => $this->filterApplier->apply($q, $filters))
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
            $map[self::toInt($row->id)] = self::toString($row->name);
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
}
