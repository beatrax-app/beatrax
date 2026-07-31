<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire\Concerns;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Dto\ForecastDto;
use Modules\Forecasting\Public\Services\ForecastQuery;
use stdClass;

// The chart-building half of ForecastPage: the ApexCharts option payload,
// the shared y-axis range, the day-30 net-difference maths and the
// all-accounts aggregate rollup. Split out so the component keeps its
// action surface under the method ceiling.
/**
 * @link ../../../../../../.docs/features/forecasting/architecture.md
 */
trait BuildsForecastCharts
{
    // Panel accent colours. The scenario panel is tinted by whether the
    // scenario leaves the user better or worse off at day 30; the
    // baseline panel is always neutral.
    private const NEUTRAL_INK = '#0F172A';

    private const BETTER_OFF_INK = '#047857';

    private const WORSE_OFF_INK = '#BE123C';

    /**
     * @link ../../../../../../.docs/features/forecasting/architecture.md
     *
     * @param  list<array{id: int, name: string, default_currency: string, kind: string}>  $accountList
     * @return array{0: list<array{date: string, point_minor: int}>, 1: int}
     */
    private function computeAllAccountsAggregate(
        array $accountList,
        int $horizon,
        ForecastQuery $forecastQuery,
        DatabaseManager $db,
        User $user,
    ): array {
        /** @var array<string, int> $byDate */
        $byDate = [];
        foreach ($accountList as $account) {
            $dto = $forecastQuery->forUser($account['id'], $horizon, null, $user);
            foreach ($dto->points as $p) {
                $byDate[$p->date] = ($byDate[$p->date] ?? 0) + $p->pointMinor;
            }
        }

        ksort($byDate, SORT_STRING);
        $aggregatePoints = [];
        foreach ($byDate as $date => $sumPoint) {
            $aggregatePoints[] = ['date' => $date, 'point_minor' => $sumPoint];
        }

        $bufferRows = $db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->whereNotNull('forecast_min_buffer_minor')
            ->get(['forecast_min_buffer_minor']);
        $bufferFloor = 0;
        foreach ($bufferRows as $row) {
            /** @var stdClass $row */
            if (is_numeric($row->forecast_min_buffer_minor ?? null)) {
                $bufferFloor += (int) $row->forecast_min_buffer_minor;
            }
        }

        return [$aggregatePoints, $bufferFloor];
    }

    // Computed here rather than left to ApexCharts auto-scale so both
    // panels render against identical y-axis bounds — otherwise the
    // scenario panel's independent auto-scale would visually distort
    // the baseline-vs-scenario delta.
    /**
     * @return array{0: float, 1: float}
     */
    private function computeSharedYAxisRange(ForecastDto $baseline, ?ForecastDto $scenario, ?int $effectiveBufferMinor): array
    {
        $lows = [];
        $highs = [];
        foreach ($baseline->points as $p) {
            $lows[] = $p->lowMinor;
            $highs[] = $p->highMinor;
        }
        if ($scenario !== null) {
            foreach ($scenario->points as $p) {
                $lows[] = $p->lowMinor;
                $highs[] = $p->highMinor;
            }
        }
        if ($effectiveBufferMinor !== null) {
            $lows[] = $effectiveBufferMinor;
            $highs[] = $effectiveBufferMinor;
        }

        $yMin = ($lows === [] ? 0 : min($lows)) / 100 - 1;
        $yMax = ($highs === [] ? 0 : max($highs)) / 100 + 1;

        return [$yMin, $yMax];
    }

    // Tints the scenario panel by the day-30 net difference. Exactly
    // zero is neutral rather than green: an unchanged balance is not an
    // improvement, and colouring it as one overstates the scenario.
    private function panelColorFor(int $netDiffMinor): string
    {
        return match (true) {
            $netDiffMinor > 0 => self::BETTER_OFF_INK,
            $netDiffMinor < 0 => self::WORSE_OFF_INK,
            default => self::NEUTRAL_INK,
        };
    }

    /**
     * @return array<int, int>
     */
    private function computeNetDiff(ForecastDto $baseline, ForecastDto $scenario): array
    {
        $result = [];
        foreach (ProjectForecastJob::HORIZON_DAYS as $horizonKey) {
            $result[$horizonKey] = 0;
        }
        foreach (ProjectForecastJob::HORIZON_DAYS as $horizonKey) {
            if ($horizonKey > $baseline->horizonDays || $horizonKey > $scenario->horizonDays) {
                continue;
            }
            $b = $this->pointAtIndex($baseline, $horizonKey);
            $s = $this->pointAtIndex($scenario, $horizonKey);
            // If either DTO is missing the day-N point (malformed
            // result_json), skip this horizon rather than substituting
            // the last-available point's value as if it were day-N.
            if ($b === null || $s === null) {
                continue;
            }
            $result[$horizonKey] = $s - $b;
        }

        return $result;
    }

    private function pointAtIndex(ForecastDto $dto, int $dayOffset): ?int
    {
        $points = $dto->points;
        if ($points === []) {
            return null;
        }
        if ($dayOffset > count($points) - 1) {
            // Missing day-N point — surface a "skip" signal rather than
            // silently substituting the last available point.
            return null;
        }

        return $points[$dayOffset]->pointMinor;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildApexOptions(
        ForecastDto $forecast,
        ?int $effectiveBufferMinor = null,
        ?float $yMinOverride = null,
        ?float $yMaxOverride = null,
        string $panelColor = self::NEUTRAL_INK,
    ): array {
        $rangeData = [];
        $lineData = [];
        $lows = [];
        $highs = [];

        foreach ($forecast->points as $point) {
            $rangeData[] = ['x' => $point->date, 'y' => [$point->lowMinor / 100, $point->highMinor / 100]];
            $lineData[] = ['x' => $point->date, 'y' => $point->pointMinor / 100];
            $lows[] = $point->lowMinor;
            $highs[] = $point->highMinor;
        }

        $yMin = $yMinOverride ?? (($lows === [] ? 0 : min($lows)) / 100 - 1);
        $yMax = $yMaxOverride ?? (($highs === [] ? 0 : max($highs)) / 100 + 1);

        // Render the shortfall-band region BELOW the buffer floor so the
        // user sees immediately where the projected balance dips below it.
        // ApexCharts v5 requires the full annotations object shape: a bare
        // [] serializes to a JSON array and crashes drawImageAnnos.
        $annotations = ['yaxis' => [], 'xaxis' => [], 'points' => [], 'images' => []];
        if ($effectiveBufferMinor !== null) {
            $bufferValue = $effectiveBufferMinor / 100;
            $annotations['yaxis'] = [
                [
                    'y' => $yMin - 10,
                    'y2' => $bufferValue,
                    'fillColor' => '#FECDD3',
                    'opacity' => 0.4,
                    'label' => ['text' => '', 'position' => 'left'],
                ],
            ];
        }

        return [
            'chart' => [
                'type' => 'rangeArea',
                'height' => 320,
                'animations' => ['enabled' => false],
                'toolbar' => ['show' => false],
                'zoom' => ['enabled' => false],
                'fontFamily' => 'inherit',
            ],
            'series' => [
                ['name' => 'Range', 'type' => 'rangeArea', 'data' => $rangeData],
                ['name' => 'Point estimate', 'type' => 'line', 'data' => $lineData],
            ],
            'dataLabels' => ['enabled' => false],
            'fill' => ['opacity' => [0.2, 1.0]],
            'stroke' => ['curve' => 'straight', 'width' => [0, 2.5]],
            'colors' => [$panelColor, $panelColor],
            'xaxis' => [
                'type' => 'datetime',
                'labels' => ['style' => ['fontSize' => '12px', 'colors' => '#64748B']],
            ],
            'yaxis' => [
                'min' => $yMin,
                'max' => $yMax,
                'forceNiceScale' => true,
                'labels' => ['style' => ['fontSize' => '12px', 'colors' => '#64748B']],
            ],
            'grid' => ['borderColor' => '#E2E8F0', 'strokeDashArray' => 0],
            'legend' => ['show' => false],
            'tooltip' => ['shared' => true, 'intersect' => false],
            'annotations' => $annotations,
            // Phone-tuned responsive breakpoints baked into server-rendered
            // options so the chart fills the container at phone width with
            // fewer x-axis labels and no legend — no extra JS required.
            'responsive' => [
                [
                    'breakpoint' => 768,
                    'options' => [
                        'chart' => ['height' => 240],
                        'xaxis' => ['tickAmount' => 4],
                        'legend' => ['show' => false],
                    ],
                ],
            ],
        ];
    }
}
