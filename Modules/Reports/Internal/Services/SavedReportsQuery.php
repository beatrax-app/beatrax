<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Reports\Internal\Dto\SavedReportIndexRow;
use Modules\Reports\Internal\Enums\ReportDimension;
use Modules\Reports\Internal\Enums\ReportMetricSelection;
use Modules\Reports\Internal\Enums\ReportPeriodPreset;
use Modules\Reports\Internal\Support\DefinitionJsonDecoder;
use stdClass;

final readonly class SavedReportsQuery
{
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

        // Asked of the enums rather than a second copy of their cases: a list
        // that drifts from them fails by picking the fallback label, which
        // reads as a deliberate "unnamed" rather than as a missing case.
        $metricKey = ReportMetricSelection::tryFrom($metric)->value ?? 'fallback';
        $periodKey = ReportPeriodPreset::tryFrom($period)->value ?? ReportPeriodPreset::Custom->value;

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

        $dimensionKey = ReportDimension::tryFrom($dimension)->value ?? 'fallback';
        $dimensionLabel = Lang::get("reports::index.summary.dimension.{$dimensionKey}");

        return Lang::get('reports::index.summary.with_dimension', [
            'metric' => $metricLabel,
            'dimension' => $dimensionLabel,
            'period' => $periodLabel,
        ]);
    }
}
