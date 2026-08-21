<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Reports\Public\Dto\ReportResultRow;
use Modules\Reports\Public\Services\PinnedReportsQuery;

final class PinnedReportsRow extends Component
{
    private const CHART_HEIGHT = 180;

    private const DONUT_HEIGHT = 240;

    /** @var list<string> */
    private const DONUT_PALETTE = ['#0F172A', '#334155', '#64748B', '#94A3B8', '#0EA5E9', '#059669', '#B45309', '#BE123C', '#7C3AED', '#0891B2'];

    public function render(
        CurrentUser $currentUser,
        PinnedReportsQuery $query,
        ReportAggregator $aggregator,
        ViewFactory $views,
    ): View {
        if (! $currentUser->isAuthenticated()) {
            return $views->make('reports::livewire.pinned-reports-row', ['cards' => []]);
        }

        $user = $currentUser->user();
        $pins = $query->forUser($user);

        $cards = [];
        foreach ($pins as $pin) {
            $definition = $pin['definition'];
            $result = $aggregator->run($user, $definition);

            $cards[] = [
                'id' => $pin['id'],
                'name' => $pin['name'],
                'chartElementId' => 'pinned-report-chart-'.$pin['id'],
                'optionsJson' => $this->chartOptionsJson($definition, $result->rows),
            ];
        }

        return $views->make('reports::livewire.pinned-reports-row', ['cards' => $cards]);
    }

    /**
     * @param  list<ReportResultRow>  $rows
     */
    private function chartOptionsJson(ReportDefinition $definition, array $rows): string
    {
        $chartType = $this->chartTypeFor($definition);

        $options = $chartType === 'donut'
            ? $this->donutOptions($rows)
            : $this->seriesOptions($chartType, $rows);

        $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $optionsJson === false ? '{}' : $optionsJson;
    }

    // The mini card is chart-only, so a 'table' viz falls back to the builder's
    // own metric default: net-worth/time-series -> line, else -> bar.
    private function chartTypeFor(ReportDefinition $definition): string
    {
        if (in_array($definition->viz, ['bar', 'line', 'donut'], true)) {
            return $definition->viz;
        }

        return ($definition->metric === 'net_worth' || $definition->dimension === 'time_bucket') ? 'line' : 'bar';
    }

    /**
     * @param  list<ReportResultRow>  $rows
     * @return array<string, mixed>
     */
    private function seriesOptions(string $chartType, array $rows): array
    {
        $rows = self::withoutLeadingEmptyBuckets($rows);

        $categories = array_map(static fn (ReportResultRow $row): string => $row->groupLabel, $rows);
        $data = array_map(static fn (ReportResultRow $row): float => $row->amountMinor / 100, $rows);

        return [
            'chart' => [
                'type' => $chartType,
                'height' => self::CHART_HEIGHT,
                'animations' => ['enabled' => false],
                'toolbar' => ['show' => false],
                'zoom' => ['enabled' => false],
                'fontFamily' => 'Inter, system-ui, sans-serif',
            ],
            'series' => [
                ['data' => $data],
            ],
            'stroke' => $chartType === 'line' ? ['curve' => 'straight', 'width' => 2] : ['width' => 0],
            'plotOptions' => $chartType === 'bar' ? ['bar' => ['borderRadius' => 2, 'columnWidth' => '55%']] : [],
            'colors' => ['#0F172A'],
            'dataLabels' => ['enabled' => false],
            'xaxis' => [
                'categories' => $categories,
                'labels' => ['show' => false],
                'axisTicks' => ['show' => false],
                'axisBorder' => ['show' => false],
            ],
            'yaxis' => ['show' => false],
            'grid' => ['show' => false],
            'legend' => ['show' => false],
            'tooltip' => ['enabled' => true],
        ];
    }

    /**
     * @param  list<ReportResultRow>  $rows
     * @return list<ReportResultRow>
     */
    private static function withoutLeadingEmptyBuckets(array $rows): array
    {
        // The time-bucket query emits a row per bucket regardless, so a window
        // opening before the first transaction starts with a meaningless flat
        // run. Only the leading run goes: a zero between funded buckets is data.
        $firstFunded = null;
        foreach ($rows as $index => $row) {
            if ($row->amountMinor !== 0) {
                $firstFunded = $index;
                break;
            }
        }

        // With every bucket empty there is nothing to lead into: a flat line at
        // zero reads as "zero everywhere", an empty series as "no such report".
        if ($firstFunded === null) {
            return $rows;
        }

        return array_slice($rows, $firstFunded);
    }

    /**
     * @param  list<ReportResultRow>  $rows
     * @return array<string, mixed>
     */
    private function donutOptions(array $rows): array
    {
        $labels = array_map(static fn (ReportResultRow $row): string => $row->groupLabel, $rows);
        // ApexCharts donut series wants non-negative magnitudes, but a report
        // total is signed, so slice size is the absolute value.
        $series = array_map(static fn (ReportResultRow $row): float => abs($row->amountMinor) / 100, $rows);

        $colors = [];
        foreach (array_keys($labels) as $i) {
            $colors[] = self::DONUT_PALETTE[$i % count(self::DONUT_PALETTE)];
        }

        return [
            'chart' => [
                'type' => 'donut',
                // Taller than the bar/line cards: the legend below the ring is
                // what makes the slices mean anything, and it needs the room.
                'height' => self::DONUT_HEIGHT,
                'animations' => ['enabled' => false],
                'toolbar' => ['show' => false],
                'fontFamily' => 'Inter, system-ui, sans-serif',
            ],
            'series' => $series,
            'labels' => $labels,
            'colors' => $colors,
            'dataLabels' => ['enabled' => false],
            // The bar and line cards carry meaning in the axis; a donut has
            // nowhere else to put it, and hover is unavailable on a phone.
            'legend' => [
                'show' => true,
                'position' => 'bottom',
                'fontSize' => '11px',
                'itemMargin' => ['horizontal' => 6, 'vertical' => 2],
                'labels' => ['colors' => '#64748B'],
            ],
            'tooltip' => ['enabled' => true],
        ];
    }
}
