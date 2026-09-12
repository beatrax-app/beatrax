<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Dto\ReportResultRow;
use Modules\Reports\Internal\Enums\ReportGranularity;
use stdClass;

final readonly class TimeBucketSpendQuery
{
    use CoercesScalars;

    private const string BUCKETS = 'report_buckets';

    public function __construct(
        private DatabaseManager $db,
        private TimeBucketGenerator $timeBucketGenerator,
        private SpendFilterApplier $filterApplier,
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
        ?ReportGranularity $granularity = null,
        SpendQueryFilters $filters = new SpendQueryFilters,
    ): array {
        $buckets = $this->timeBucketGenerator->generate($period, $granularity ?? ReportGranularity::default());
        $reportMetric = ReportMetric::fromMetric($metric);
        $counted = $reportMetric->predicate();
        $amountExpr = $this->filterApplier->amountExpr($reportMetric, $filters);
        $connection = $this->db->connection();

        $totals = $buckets === [] ? [] : $this->totalsByBucket(
            $connection->table('transactions')
                ->where('user_id', $user->id)
                ->whereRaw(...$counted)
                ->where('settled_currency', $currency)
                ->tap(fn (QueryBuilder $q): QueryBuilder => $this->filterApplier->apply($q, $filters))
                ->addBinding(self::bucketEdges($buckets), 'join')
                ->joinSub(self::bucketRowsSql(count($buckets)), self::BUCKETS, static function (JoinClause $join): void {
                    // Half-open and contiguous by construction, so no
                    // transaction can be counted into two of them.
                    $join->on('transactions.posted_at', '>=', self::BUCKETS.'.bucket_start')
                        ->on('transactions.posted_at', '<', self::BUCKETS.'.bucket_end');
                })
                ->groupBy(self::BUCKETS.'.bucket_index')
                ->selectRaw(self::BUCKETS.'.bucket_index AS bucket_index')
                ->selectRaw($amountExpr.' AS amount_minor'),
        );

        $result = [];
        foreach ($buckets as $index => $bucket) {
            $result[] = new ReportResultRow(
                groupKey: $bucket->start->toDateString(),
                groupLabel: $bucket->label,
                // A bucket no transaction falls in has no row, which is the
                // same nothing the per-bucket aggregate used to return.
                amountMinor: $totals[$index] ?? 0,
                currency: $currency,
            );
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    private function totalsByBucket(QueryBuilder $query): array
    {
        $totals = [];
        foreach ($query->get() as $row) {
            /** @var stdClass $row */
            $totals[self::toInt($row->bucket_index)] = self::toInt($row->amount_minor);
        }

        return $totals;
    }

    // The bucket edges as rows, so the chart is one statement and not one per
    // point; MAX_BUCKET_POINTS is what bounds the union. Written out rather
    // than composed from sub-builders, whose union grammar wraps each arm in a
    // subquery and spends more on co-routines than the round trips it saved.
    private static function bucketRowsSql(int $count): string
    {
        return implode(' union all ', array_fill(
            0,
            $count,
            'select ? as bucket_index, ? as bucket_start, ? as bucket_end',
        ));
    }

    /**
     * @param  list<Period>  $buckets
     * @return list<int|string>
     */
    private static function bucketEdges(array $buckets): array
    {
        $edges = [];
        foreach ($buckets as $index => $bucket) {
            $edges[] = $index;
            $edges[] = $bucket->start->toDateString();
            $edges[] = $bucket->endExclusive->toDateString();
        }

        return $edges;
    }
}
