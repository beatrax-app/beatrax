<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Dto\ReportResultDto;
use Modules\Reports\Internal\Dto\ReportResultRow;
use Modules\Reports\Internal\Enums\ReportDimension;
use Modules\Reports\Internal\Enums\ReportMetricSelection;
use Modules\Reports\Internal\Enums\ReportViz;
use Modules\Reports\Internal\Exceptions\InvalidReportPeriod;
use Modules\Reports\Internal\Services\PinnedReportsQuery;
use Modules\Reports\Internal\Support\ChartAmount;
use Modules\Reports\Internal\Support\ChartSeries;
use Modules\Reports\Internal\Support\DonutPalette;

final class PinnedReportsRow extends Component
{
    private const int CHART_HEIGHT = 180;

    private const int DONUT_HEIGHT = 240;

    private const string AXIS_LABEL_COLOUR = '#64748B';

    public function render(
        CurrentUser $currentUser,
        PinnedReportsQuery $query,
        ReportAggregator $aggregator,
        ViewFactory $views,
        BaseCurrency $baseCurrency,
    ): View {
        if (! $currentUser->isAuthenticated()) {
            return $views->make('reports::livewire.pinned-reports-row', ['cards' => []]);
        }

        $user = $currentUser->user();
        $pins = $query->forUser($user);

        $cards = [];
        foreach ($pins as $pin) {
            $definition = $pin['definition'];

            // A saved definition whose custom range cannot resolve renders as an
            // empty card. It is one card of three on a page that is not about
            // reports, and taking the dashboard down with it is never the right
            // trade -- the builder is where the range gets fixed, and it says so.
            try {
                $result = $aggregator->run($user, $definition);
            } catch (InvalidReportPeriod) {
                $result = new ReportResultDto(rows: [], totalMinor: 0, currency: $baseCurrency->forUser($user));
            }

            $cards[] = [
                'id' => $pin['id'],
                'name' => $pin['name'],
                'chartElementId' => 'pinned-report-chart-'.$pin['id'],
                'optionsJson' => $this->chartOptionsJson($definition, $result),
            ];
        }

        return $views->make('reports::livewire.pinned-reports-row', ['cards' => $cards]);
    }

    private function chartOptionsJson(ReportDefinition $definition, ReportResultDto $result): string
    {
        $chartType = $this->chartTypeFor($definition);

        $options = $chartType === ReportViz::Donut->value
            ? $this->donutOptions($result->rows, $result->totalMinor, $result->currency)
            : $this->seriesOptions($chartType, $result->rows, $result->currency);

        $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $optionsJson === false ? '{}' : $optionsJson;
    }

    // The mini card is chart-only, so a 'table' viz falls back to the builder's
    // own metric default: net-worth/time-series -> line, else -> bar.
    private function chartTypeFor(ReportDefinition $definition): string
    {
        // Asked of the enum rather than a second copy of its cases: Table is the
        // one case a chart cannot be drawn as, so everything else is drawable.
        $viz = ReportViz::tryFrom($definition->viz);

        if ($viz !== null && $viz !== ReportViz::Table) {
            return $viz->value;
        }

        return ($definition->metric === ReportMetricSelection::NetWorth->value || $definition->dimension === ReportDimension::TimeBucket->value) ? ReportViz::Line->value : ReportViz::Bar->value;
    }

    /**
     * @param  list<ReportResultRow>  $rows
     * @return array<string, mixed>
     */
    private function seriesOptions(string $chartType, array $rows, string $currency): array
    {
        // One axis, one currency: raw minor units of two of them share no
        // scale, so a JPY row was plotted a hundredfold beside a EUR one.
        $series = ChartSeries::for($chartType, $rows, [], $currency, 0);
        $rows = self::withoutLeadingEmptyBuckets($series->rows);

        $categories = array_map(static fn (ReportResultRow $row): string => $row->groupLabel, $rows);
        $data = ChartAmount::series($rows);

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
            'stroke' => $chartType === ReportViz::Line->value ? ['curve' => 'straight', 'width' => 2] : ['width' => 0],
            'plotOptions' => $chartType === ReportViz::Bar->value ? ['bar' => ['borderRadius' => 2, 'columnWidth' => '55%']] : [],
            'colors' => ['#0F172A'],
            'beatraxCurrency' => $series->currency,
            'dataLabels' => ['enabled' => false],
            // The bucket labels are this card's legend: without them the bars
            // name nothing, and hover is unavailable on a phone. Ticks and
            // border stay off — the labels carry the meaning, the furniture
            // does not.
            'xaxis' => [
                'categories' => $categories,
                'labels' => [
                    'show' => true,
                    'rotate' => 0,
                    'hideOverlappingLabels' => true,
                    'trim' => true,
                    'style' => ['fontSize' => '11px', 'colors' => self::AXIS_LABEL_COLOUR],
                ],
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
        $firstFunded = array_find_key($rows, fn ($row): bool => $row->amountMinor !== 0);
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
    private function donutOptions(array $rows, int $totalMinor = 0, string $currency = ''): array
    {
        // Only the rows moving the way the total does: a ring is built from
        // sizes, and abs() drew a refund as a slice of the spending it had
        // already been subtracted from.
        $series = ChartSeries::for(ReportViz::Donut->value, $rows, [], $currency, $totalMinor);
        $labels = array_map(static fn (ReportResultRow $row): string => $row->groupLabel, $series->rows);

        return [
            'chart' => [
                'type' => ReportViz::Donut->value,
                // Taller than the bar/line cards: the legend below the ring is
                // what makes the slices mean anything, and it needs the room.
                'height' => self::DONUT_HEIGHT,
                'animations' => ['enabled' => false],
                'toolbar' => ['show' => false],
                'fontFamily' => 'Inter, system-ui, sans-serif',
            ],
            'series' => ChartAmount::magnitudes($series->rows),
            'labels' => $labels,
            'colors' => DonutPalette::forSlices(count($labels)),
            'beatraxCurrency' => $series->currency,
            'dataLabels' => ['enabled' => false],
            // The bar and line cards carry meaning in the axis; a donut has
            // nowhere else to put it, and hover is unavailable on a phone.
            'legend' => [
                'show' => true,
                'position' => 'bottom',
                'fontSize' => '11px',
                'itemMargin' => ['horizontal' => 6, 'vertical' => 2],
                'labels' => ['colors' => self::AXIS_LABEL_COLOUR],
            ],
            'tooltip' => ['enabled' => true],
        ];
    }
}
