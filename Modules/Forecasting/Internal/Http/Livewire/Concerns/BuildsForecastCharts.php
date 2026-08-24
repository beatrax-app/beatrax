<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire\Concerns;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Dto\ForecastDto;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

trait BuildsForecastCharts
{
    private const NEUTRAL_INK = '#0F172A';

    private const BETTER_OFF_INK = '#047857';

    private const WORSE_OFF_INK = '#BE123C';

    /**
     * @param  list<array{id: int, name: string, default_currency: string, kind: string}>  $accountList
     * @return array{0: list<array{date: string, point_minor: int}>, 1: int}
     */
    private function computeAllAccountsAggregate(
        array $accountList,
        int $horizon,
        ForecastQuery $forecastQuery,
        DatabaseManager $db,
        User $user,
        CrossCurrencyTotal $fx,
        string $baseCurrency,
    ): array {
        /** @var array<string, array<string, int>> $byDateCurrency */
        $byDateCurrency = [];
        foreach ($accountList as $account) {
            $dto = $forecastQuery->forUser($account['id'], $horizon, null, $user);
            foreach ($dto->points as $p) {
                $currency = self::denominationOf($p->currency, $dto->defaultCurrency, $account['default_currency'], $baseCurrency);
                $byDateCurrency[$p->date][$currency] = ($byDateCurrency[$p->date][$currency] ?? 0) + $p->pointMinor;
            }
        }

        ksort($byDateCurrency, SORT_STRING);
        // A projection is denominated in the account's own code, and the
        // aggregate is one line in the reader's. Adding the two at face value
        // read EUR2,000.00 for EUR1,000.00 next to USD1,000.00.
        $rates = $fx->ratesTo($this->currenciesIn($byDateCurrency), $baseCurrency);

        $aggregatePoints = [];
        foreach ($byDateCurrency as $date => $byCurrency) {
            $aggregatePoints[] = ['date' => $date, 'point_minor' => $fx->withRates($byCurrency, $baseCurrency, $rates)->minor];
        }

        $bufferRows = $db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->whereNotNull('forecast_min_buffer_minor')
            ->get(['forecast_min_buffer_minor', 'default_currency']);
        /** @var array<string, int> $bufferByCurrency */
        $bufferByCurrency = [];
        foreach ($bufferRows as $row) {
            /** @var stdClass $row */
            if (! is_numeric($row->forecast_min_buffer_minor ?? null)) {
                continue;
            }
            $rowCurrency = $row->default_currency ?? null;
            $currency = self::denominationOf(is_string($rowCurrency) ? $rowCurrency : '', '', '', $baseCurrency);
            $bufferByCurrency[$currency] = ($bufferByCurrency[$currency] ?? 0) + (int) $row->forecast_min_buffer_minor;
        }

        $bufferRates = $fx->ratesTo(array_keys($bufferByCurrency), $baseCurrency);

        return [$aggregatePoints, $fx->withRates($bufferByCurrency, $baseCurrency, $bufferRates)->minor];
    }

    /**
     * @param  array<string, array<string, int>>  $byDateCurrency
     * @return list<string>
     */
    private function currenciesIn(array $byDateCurrency): array
    {
        $seen = [];
        foreach ($byDateCurrency as $byCurrency) {
            foreach (array_keys($byCurrency) as $currency) {
                $seen[$currency] = true;
            }
        }

        return array_keys($seen);
    }

    // A run written before the point carried its own code, or an account row
    // that lost its, still has to land in some bucket; the reader's own
    // currency is the last one left that is not a guess.
    private static function denominationOf(string $pointCurrency, string $dtoCurrency, string $accountCurrency, string $baseCurrency): string
    {
        foreach ([$pointCurrency, $dtoCurrency, $accountCurrency] as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $baseCurrency;
    }

    // Computed rather than left to ApexCharts auto-scale: independent scaling
    // per panel would visually distort the baseline-vs-scenario delta.
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

        $yMin = ($lows === [] ? 0 : min($lows)) / Money::MINOR_UNITS_PER_MAJOR - 1;
        $yMax = ($highs === [] ? 0 : max($highs)) / Money::MINOR_UNITS_PER_MAJOR + 1;

        return [$yMin, $yMax];
    }

    // Exactly zero is neutral, not green: an unchanged balance is not an
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
            // A malformed result_json can be short of day N. Skipping the
            // horizon beats passing off the last point as if it were day N.
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
            $rangeData[] = [
                'x' => $point->date,
                'y' => [$point->lowMinor / Money::MINOR_UNITS_PER_MAJOR, $point->highMinor / Money::MINOR_UNITS_PER_MAJOR],
            ];
            $lineData[] = ['x' => $point->date, 'y' => $point->pointMinor / Money::MINOR_UNITS_PER_MAJOR];
            $lows[] = $point->lowMinor;
            $highs[] = $point->highMinor;
        }

        $yMin = $yMinOverride ?? (($lows === [] ? 0 : min($lows)) / Money::MINOR_UNITS_PER_MAJOR - 1);
        $yMax = $yMaxOverride ?? (($highs === [] ? 0 : max($highs)) / Money::MINOR_UNITS_PER_MAJOR + 1);

        // ApexCharts v5 needs the full annotations object: a bare [] serializes
        // to a JSON array and crashes drawImageAnnos.
        $annotations = ['yaxis' => [], 'xaxis' => [], 'points' => [], 'images' => []];
        if ($effectiveBufferMinor !== null) {
            $bufferValue = $effectiveBufferMinor / Money::MINOR_UNITS_PER_MAJOR;
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
            // The axis formatter in app.js has no other way to know what these
            // points are denominated in; without it the page-level reporting
            // currency wins and the numbers keep the wrong symbol.
            'beatraxCurrency' => $forecast->defaultCurrency,
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
