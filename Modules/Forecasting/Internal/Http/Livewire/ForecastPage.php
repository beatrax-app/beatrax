<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Forecasting\Public\Dto\ForecastDto;
use Modules\Forecasting\Public\Dto\ForecastPointDto;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/forecast` Livewire SFC — the dedicated cash-flow forecast surface.
 *
 * Wave 2 surface is intentionally narrow: a per-account rangeArea
 * chart for the baseline projection, the 30 / 60 / 90 horizon
 * segmented control, and the per-account tab bar. Wave 3 adds the
 * shortfall band overlay + buffer editor trigger, Wave 4 lands the
 * scenario picker + side-by-side, Wave 5 brings the percentile-tier
 * confidence legend and the All-accounts aggregate chart.
 *
 * Service collaborators arrive as parameters on action methods and
 * `render()`. Constructor injection is banned on Livewire `Component`
 * subclasses by phpstan-strict-rules.
 */
final class ForecastPage extends Component
{
    /**
     * Selected account id (or the `'all'` sentinel which Wave 2 maps
     * to the first per-account chart alphabetically; Wave 5 renders a
     * true aggregate).
     */
    #[Url(as: 'account', except: 'all')]
    public string $account = 'all';

    /**
     * Active projection horizon — one of 30 / 60 / 90 (days).
     */
    #[Url(as: 'horizon', except: 30)]
    public int $horizon = 30;

    /**
     * Active scenario id. Wave 2 keeps the baseline-only surface; the
     * URL param is wired now so Wave 4 inherits the state contract.
     */
    #[Url(as: 'scenarioId', except: null)]
    public ?int $scenarioId = null;

    /**
     * View-by-funder toggle. Wave 3 lights up the routing of an ICS
     * card account's outflows back to the funding ASN account; the
     * URL param ships here so the Wave 3 swap-in is body-only.
     */
    #[Url(as: 'viewByFunder', except: false)]
    public bool $viewByFunder = false;

    public function setHorizon(int $days): void
    {
        if (! in_array($days, [30, 60, 90], true)) {
            return;
        }
        $this->horizon = $days;
        $this->dispatch('forecast-updated');
    }

    public function setAccount(string $accountId): void
    {
        $this->account = $accountId;
        $this->dispatch('forecast-updated');
    }

    public function render(
        CurrentUser $currentUser,
        ForecastQuery $forecastQuery,
        ScenarioQuery $scenarioQuery,
        DatabaseManager $db,
        ViewFactory $views,
        Clock $clock,
    ): View {
        $user = $currentUser->user();

        $accounts = $db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'default_currency', 'kind']);

        $accountList = [];
        $firstAccountId = null;
        foreach ($accounts as $account) {
            /** @var stdClass $account */
            $accountId = self::toInt($account->id);
            $accountList[] = [
                'id' => $accountId,
                'name' => self::toString($account->name ?? null),
                'default_currency' => self::toString($account->default_currency ?? null),
                'kind' => self::toString($account->kind ?? null),
            ];
            if ($firstAccountId === null) {
                $firstAccountId = $accountId;
            }
        }

        if ($this->account === 'all') {
            $selectedAccountId = $firstAccountId;
        } else {
            $selectedAccountId = is_numeric($this->account) ? (int) $this->account : null;
            if ($selectedAccountId !== null) {
                $owns = false;
                foreach ($accountList as $a) {
                    if ($a['id'] === $selectedAccountId) {
                        $owns = true;
                        break;
                    }
                }
                if (! $owns) {
                    throw new NotFoundHttpException('Account not found.');
                }
            }
        }

        // Empty-state guard: no accounts at all OR no first account selected.
        $isEmpty = $selectedAccountId === null;

        $baseline = null;
        $apexOptions = null;
        $chartElementId = null;
        $todayBalanceMinor = 0;
        $horizonLowMinor = 0;
        $horizonHighMinor = 0;
        $defaultCurrency = 'EUR';

        if ($selectedAccountId !== null) {
            $baseline = $forecastQuery->forUser($selectedAccountId, $this->horizon, null, $user);
            $todayBalanceMinor = $baseline->todayBalanceMinor;
            $defaultCurrency = $baseline->defaultCurrency;
            $lastPoint = $baseline->points === [] ? null : $baseline->points[count($baseline->points) - 1];
            if ($lastPoint instanceof ForecastPointDto) {
                $horizonLowMinor = $lastPoint->lowMinor;
                $horizonHighMinor = $lastPoint->highMinor;
            }
            $apexOptions = $this->buildApexOptions($baseline);
            $chartElementId = 'forecast-chart-baseline-'.$selectedAccountId;
        }

        // Scenario picker (Wave 2 baseline-only — read the user's saved scenarios so the picker is forward-complete for Wave 4).
        $scenarios = $scenarioQuery->forUser($user);

        $now = $clock->now();
        unset($now); // Reserved for Wave 4 — keeps the Clock constructor-injected via render() parameter resolution.

        $view = $views->make('forecasting::livewire.forecast-page', [
            'accounts' => $accountList,
            'selectedAccountId' => $selectedAccountId,
            'baseline' => $baseline,
            'apexOptions' => $apexOptions,
            'chartElementId' => $chartElementId,
            'horizon' => $this->horizon,
            'isEmpty' => $isEmpty,
            'todayBalanceMinor' => $todayBalanceMinor,
            'horizonLowMinor' => $horizonLowMinor,
            'horizonHighMinor' => $horizonHighMinor,
            'defaultCurrency' => $defaultCurrency,
            'scenarios' => $scenarios,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Forecast · diederik']);

        return $view;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildApexOptions(ForecastDto $baseline): array
    {
        $rangeData = [];
        $lineData = [];
        $lows = [];
        $highs = [];

        foreach ($baseline->points as $point) {
            $rangeData[] = ['x' => $point->date, 'y' => [$point->lowMinor / 100, $point->highMinor / 100]];
            $lineData[] = ['x' => $point->date, 'y' => $point->pointMinor / 100];
            $lows[] = $point->lowMinor;
            $highs[] = $point->highMinor;
        }

        $yMin = ($lows === [] ? 0 : min($lows)) / 100 - 1;
        $yMax = ($highs === [] ? 0 : max($highs)) / 100 + 1;

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
            'fill' => ['opacity' => [0.2, 1.0]],
            'stroke' => ['curve' => 'straight', 'width' => [0, 2.5]],
            'colors' => ['#0F172A', '#0F172A'],
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
        ];
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
