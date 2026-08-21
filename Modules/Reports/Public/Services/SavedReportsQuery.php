<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Reports\Internal\Support\DefinitionJsonDecoder;
use Modules\Reports\Public\Dto\SavedReportIndexRow;
use stdClass;

final readonly class SavedReportsQuery
{
    /** @var list<string> */
    private const METRIC_KEYS = ['spend', 'income', 'net', 'net_worth'];

    /** @var list<string> */
    private const DIMENSION_KEYS = ['category', 'time_bucket', 'counterparty', 'account'];

    /** @var list<string> */
    private const PERIOD_KEYS = ['this_month', 'last_3_months', 'last_6_months', 'last_12_months', 'ytd', 'this_year', 'custom'];

    public function __construct(private DatabaseManager $db) {}

    /**
     * @return Collection<int, SavedReportIndexRow>
     */
    public function forUser(User $user): Collection
    {
        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('saved_reports')
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get(['id', 'name', 'definition', 'pinned', 'pin_order']);

        /** @var list<SavedReportIndexRow> $result */
        $result = [];
        foreach ($rows as $row) {
            $id = is_numeric($row->id ?? null) ? (int) $row->id : 0;
            if ($id === 0) {
                continue;
            }

            $name = is_string($row->name ?? null) ? $row->name : '';
            $definition = DefinitionJsonDecoder::decode($row->definition ?? null);
            $pinned = (bool) ($row->pinned ?? false);
            $pinOrderRaw = $row->pin_order ?? null;
            $pinOrder = is_numeric($pinOrderRaw) ? (int) $pinOrderRaw : null;

            $result[] = new SavedReportIndexRow(
                id: $id,
                name: $name,
                summary: self::summaryFor($definition),
                pinned: $pinned,
                pinOrder: $pinOrder,
            );
        }

        return new Collection($result);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function summaryFor(array $definition): string
    {
        $metric = is_string($definition['metric'] ?? null) ? $definition['metric'] : 'spend';
        $dimension = is_string($definition['dimension'] ?? null) ? $definition['dimension'] : 'category';
        $period = is_string($definition['periodPreset'] ?? null) ? $definition['periodPreset'] : 'this_month';

        $metricKey = in_array($metric, self::METRIC_KEYS, true) ? $metric : 'fallback';
        $periodKey = in_array($period, self::PERIOD_KEYS, true) ? $period : 'custom';

        $metricLabel = Lang::get("reports::index.summary.metric.{$metricKey}");
        $periodLabel = Lang::get("reports::index.summary.period.{$periodKey}");

        // The builder hides group-by for net worth, so the summary drops the
        // "by {dimension}" segment to match.
        if ($metric === 'net_worth') {
            return Lang::get('reports::index.summary.without_dimension', [
                'metric' => $metricLabel,
                'period' => $periodLabel,
            ]);
        }

        $dimensionKey = in_array($dimension, self::DIMENSION_KEYS, true) ? $dimension : 'fallback';
        $dimensionLabel = Lang::get("reports::index.summary.dimension.{$dimensionKey}");

        return Lang::get('reports::index.summary.with_dimension', [
            'metric' => $metricLabel,
            'dimension' => $dimensionLabel,
            'period' => $periodLabel,
        ]);
    }
}
