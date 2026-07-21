<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Support\DefinitionJsonDecoder;
use Modules\Reports\Public\Dto\SavedReportIndexRow;
use stdClass;

/**
 * @link ../../../../.docs/features/reports/architecture.md
 */
final readonly class SavedReportsQuery
{
    /** @var array<string, string> */
    private const METRIC_LABELS = [
        'spend' => 'Spend',
        'income' => 'Income',
        'net' => 'Net',
        'net_worth' => 'Net worth',
    ];

    /** @var array<string, string> */
    private const DIMENSION_LABELS = [
        'category' => 'category',
        'time_bucket' => 'time bucket',
        'counterparty' => 'counterparty',
        'account' => 'account',
    ];

    /** @var array<string, string> */
    private const PERIOD_LABELS = [
        'this_month' => 'This month',
        'last_3_months' => 'Last 3 months',
        'last_6_months' => 'Last 6 months',
        'last_12_months' => 'Last 12 months',
        'ytd' => 'Year to date',
        'this_year' => 'This year',
        'custom' => 'Custom range',
    ];

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

        $metricLabel = self::METRIC_LABELS[$metric] ?? 'Amount';
        $periodLabel = self::PERIOD_LABELS[$period] ?? 'Custom range';

        // Group-by is hidden entirely for net-worth reports — the summary
        // line omits the "by {dimension}" segment to match what the
        // builder actually showed.
        if ($metric === 'net_worth') {
            return "{$metricLabel} · {$periodLabel}";
        }

        $dimensionLabel = self::DIMENSION_LABELS[$dimension] ?? 'category';

        return "{$metricLabel} · by {$dimensionLabel} · {$periodLabel}";
    }
}
