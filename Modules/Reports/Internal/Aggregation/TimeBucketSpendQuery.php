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
use Modules\Reports\Public\Enums\ReportGranularity;
use stdClass;

final class TimeBucketSpendQuery
{
    use CoercesScalars;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TimeBucketGenerator $timeBucketGenerator,
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
        ?ReportGranularity $granularity = null,
        SpendQueryFilters $filters = new SpendQueryFilters,
    ): array {
        $buckets = $this->timeBucketGenerator->generate($period, $granularity ?? ReportGranularity::default());
        $types = self::metricTypes($metric);
        $amountExpr = self::amountExpr($metric);

        $result = [];
        foreach ($buckets as $bucket) {
            $row = $this->db->connection()
                ->table('transactions')
                ->where('user_id', $user->id)
                ->whereIn('type', $types)
                ->where('settled_currency', $currency)
                ->where('posted_at', '>=', $bucket->start->toDateString())
                ->where('posted_at', '<', $bucket->endExclusive->toDateString())
                ->tap(fn (QueryBuilder $q): QueryBuilder => $this->filterApplier->apply($q, $filters))
                ->selectRaw($amountExpr.' AS amount_minor')
                ->first();

            /** @var stdClass|null $row */
            $result[] = new ReportResultRow(
                groupKey: $bucket->start->toDateString(),
                groupLabel: $bucket->label,
                amountMinor: self::toInt($row?->amount_minor),
                currency: $currency,
            );
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
}
