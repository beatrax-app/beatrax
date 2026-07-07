<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Public\Dto\ReportResultRow;
use stdClass;

/**
 * Time-bucket-dimension aggregation for the Reports builder's spend/
 * income/net metrics (Req 2/3/6/7) — one type-based, user-scoped SUM per
 * `TimeBucketGenerator`-produced sub-`Period`.
 *
 * Like `CounterpartySpendQuery`/`AccountSpendQuery`, this aggregates the
 * `transactions` parent rows directly with no split-leg join (a split
 * parent's `settled_amount_minor` already equals the sum of its legs,
 * 999.6-RESEARCH.md Pattern 2). Every generated bucket produces exactly one
 * `ReportResultRow` — including a zero-activity bucket — so a chart's
 * x-axis never silently drops a point.
 */
final class TimeBucketSpendQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TimeBucketGenerator $timeBucketGenerator,
    ) {}

    /**
     * @param  string  $metric  'spend' | 'income' | 'net'
     * @param  string  $granularity  'monthly' | 'weekly'
     * @return list<ReportResultRow>
     */
    public function forUserAndPeriod(User $user, Period $period, string $metric, string $currency, string $granularity = 'monthly'): array
    {
        $buckets = $this->timeBucketGenerator->generate($period, $granularity);
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

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
