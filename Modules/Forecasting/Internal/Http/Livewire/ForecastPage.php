<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Internal\Mapping\ForecastDtoMapper;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Actions\DeleteScenario;
use Modules\Forecasting\Public\Dto\ForecastDto;
use Modules\Forecasting\Public\Dto\ForecastPointDto;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../../.docs/features/forecasting/architecture.md
 */
final class ForecastPage extends Component
{
    use CoercesScalars;

    // 'all' is a sentinel value: it selects the All-accounts aggregate
    // view rather than a specific account id.
    #[Url(as: 'account', except: 'all')]
    public string $account = 'all';

    #[Url(as: 'horizon', except: 30)]
    public int $horizon = 30;

    #[Url(as: 'scenarioId', except: null)]
    public ?int $scenarioId = null;

    #[Url(as: 'viewByFunder', except: false)]
    public bool $viewByFunder = false;

    // Holds the scenarioId currently in "are you sure?" mode for the
    // inline two-step delete-scenario confirm; null when not confirming.
    public ?int $confirmingDeleteForScenarioId = null;

    public bool $creatingScenario = false;

    public string $newScenarioName = '';

    public ?string $createScenarioError = null;

    protected $listeners = [
        'buffer-editor:saved' => 'onBufferSaved',
        'scenario-mutated' => '$refresh',
        'scenario-renamed' => '$refresh',
        'scenario-deleted' => 'onScenarioDeleted',
    ];

    public function setHorizon(int $days): void
    {
        if (! in_array($days, ProjectForecastJob::HORIZON_DAYS, true)) {
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

    public function setScenario(?int $scenarioId): void
    {
        $this->scenarioId = $scenarioId;
        $this->confirmingDeleteForScenarioId = null;
        $this->dispatch('forecast-updated');
    }

    public function toggleViewByFunder(): void
    {
        $this->viewByFunder = ! $this->viewByFunder;
        $this->dispatch('forecast-updated');
    }

    public function startCreateScenario(): void
    {
        $this->creatingScenario = true;
        $this->newScenarioName = '';
        $this->createScenarioError = null;
    }

    public function cancelCreateScenario(): void
    {
        $this->creatingScenario = false;
        $this->newScenarioName = '';
        $this->createScenarioError = null;
    }

    public function saveNewScenario(CurrentUser $currentUser, CreateScenario $action): void
    {
        $this->createScenarioError = null;
        $name = trim($this->newScenarioName);
        if ($name === '') {
            $this->createScenarioError = 'Scenario name cannot be empty.';

            return;
        }
        try {
            $newId = ($action)($currentUser->user(), $name);
        } catch (\InvalidArgumentException $e) {
            $this->createScenarioError = $e->getMessage();

            return;
        }
        $this->creatingScenario = false;
        $this->newScenarioName = '';
        $this->scenarioId = $newId;
        $this->dispatch('toast', message: 'Scenario "'.$name.'" created.');
        $this->dispatch('forecast-updated');
    }

    public function confirmDeleteScenario(int $scenarioId): void
    {
        $this->confirmingDeleteForScenarioId = $scenarioId;
    }

    public function cancelDeleteScenario(): void
    {
        $this->confirmingDeleteForScenarioId = null;
    }

    public function deleteScenario(int $scenarioId, CurrentUser $currentUser, DeleteScenario $action, ScenarioQuery $scenarioQuery): void
    {
        // Re-validate ownership: $scenarioId is browser-supplied, so
        // ScenarioQuery's own-scenarios-only result set is re-checked here
        // rather than trusting the caller, without leaking whether a
        // foreign id exists.
        $user = $currentUser->user();
        $owns = false;
        foreach ($scenarioQuery->forUser($user) as $s) {
            if ($s->id === $scenarioId) {
                $owns = true;
                break;
            }
        }
        if (! $owns) {
            $this->confirmingDeleteForScenarioId = null;

            return;
        }
        try {
            ($action)($scenarioId, $user);
        } catch (NotFoundHttpException) {
            $this->confirmingDeleteForScenarioId = null;

            return;
        }
        $this->confirmingDeleteForScenarioId = null;
        if ($this->scenarioId === $scenarioId) {
            $this->scenarioId = null;
        }
        $this->dispatch('toast', message: 'Scenario deleted.');
        $this->dispatch('forecast-updated');
    }

    public function onBufferSaved(): void
    {
        // Re-render the chart with the new buffer floor + any newly
        // detected shortfall band. The browser-side ApexCharts wrapper
        // listens for the `forecast-updated` event on the partial.
        $this->dispatch('forecast-updated');
    }

    public function onScenarioDeleted(): void
    {
        $this->scenarioId = null;
        $this->dispatch('forecast-updated');
    }

    public function refreshProjectionStatus(): void
    {
        // wire:poll target — when the projection status flips to complete,
        // the next render unmounts the conditional wire:poll element and
        // polling halts (RESEARCH Pitfall 3 mitigation).
        $this->dispatch('forecast-updated');
    }

    public function render(
        CurrentUser $currentUser,
        ForecastQuery $forecastQuery,
        ScenarioQuery $scenarioQuery,
        DatabaseManager $db,
        ViewFactory $views,
        Clock $clock,
        ForecastDtoMapper $mapper,
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

        // A tampered or stale ?account= value (anything that is neither
        // 'all' nor a numeric account id) falls back to the All-accounts
        // tab rather than rendering a blank page with no error.
        if ($this->account !== 'all' && ! is_numeric($this->account)) {
            $this->account = 'all';
        }

        $isAllAccountsView = $this->account === 'all';
        if ($isAllAccountsView) {
            // Aggregate-tab render path. The page renders a single-line
            // EUR-rollup chart instead of a per-account rangeArea band.
            $selectedAccountId = null;
        } else {
            $selectedAccountId = (int) $this->account;
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

        $isEmpty = count($accountList) === 0;

        $baseline = null;
        $apexOptions = null;
        $chartElementId = null;
        $todayBalanceMinor = 0;
        $horizonLowMinor = 0;
        $horizonHighMinor = 0;
        $defaultCurrency = 'EUR';
        $effectiveBufferMinor = null;
        $selectedAccountName = '';
        $shortfallWindows = [];
        /** @var list<array{date: string, point_minor: int}> $aggregatePoints */
        $aggregatePoints = [];
        $aggregateBufferFloor = 0;
        $aggregateChartElementId = null;
        $aggregateCurrency = 'EUR';

        $scenario = null;
        $scenarioApexOptions = null;
        $scenarioChartElementId = null;
        $scenarioPanelColor = '#0F172A';
        /** @var array<int, int> $netDiff */
        $netDiff = [];
        foreach (ProjectForecastJob::HORIZON_DAYS as $horizonKey) {
            $netDiff[$horizonKey] = 0;
        }

        // Read the user's saved scenarios so the picker is populated;
        // shared between empty + populated states.
        $scenarios = $scenarioQuery->forUser($user);

        // Wave 4 cross-user 404 on scenarioId — refuse to render if the
        // selected scenarioId belongs to another user.
        if ($this->scenarioId !== null) {
            $owns = false;
            foreach ($scenarios as $s) {
                if ($s->id === $this->scenarioId) {
                    $owns = true;
                    break;
                }
            }
            if (! $owns) {
                throw new NotFoundHttpException('Scenario not found.');
            }
        }

        if ($selectedAccountId !== null) {
            $baseline = $forecastQuery->forUser($selectedAccountId, $this->horizon, null, $user);
            $todayBalanceMinor = $baseline->todayBalanceMinor;
            $defaultCurrency = $baseline->defaultCurrency;
            $selectedAccountName = $baseline->accountName;
            $lastPoint = $baseline->points === [] ? null : $baseline->points[count($baseline->points) - 1];
            if ($lastPoint instanceof ForecastPointDto) {
                $horizonLowMinor = $lastPoint->lowMinor;
                $horizonHighMinor = $lastPoint->highMinor;
            }

            // Load the effective per-account buffer for the popover
            // trigger label + the chart's annotations.yaxis shortfall
            // band overlay.
            $accountRow = $db->connection()->table('accounts')
                ->where('id', $selectedAccountId)
                ->where('user_id', $user->id)
                ->first(['forecast_min_buffer_minor']);
            if ($accountRow !== null && isset($accountRow->forecast_min_buffer_minor) && is_numeric($accountRow->forecast_min_buffer_minor)) {
                $effectiveBufferMinor = (int) $accountRow->forecast_min_buffer_minor;
            }

            $windowRows = $db->connection()->table('forecast_shortfall_windows')
                ->where('user_id', $user->id)
                ->where('account_id', $selectedAccountId)
                ->whereNull('scenario_id')
                ->orderBy('starts_at')
                ->get();
            foreach ($windowRows as $row) {
                /** @var stdClass $row */
                $shortfallWindows[] = $mapper->mapShortfallWindow($row);
            }

            if ($this->scenarioId !== null) {
                $scenario = $forecastQuery->forUser($selectedAccountId, $this->horizon, $this->scenarioId, $user);
                $netDiff = $this->computeNetDiff($baseline, $scenario);
                $scenarioPanelColor = $netDiff[30] > 0
                    ? '#047857'
                    : ($netDiff[30] < 0 ? '#BE123C' : '#0F172A');
            }

            // Shared y-axis (RESEARCH Pitfall 2): compute the union range
            // across both panels' points + the buffer floor so the chart's
            // y-axis is identical and the visual delta is honest.
            [$yMin, $yMax] = $this->computeSharedYAxisRange($baseline, $scenario, $effectiveBufferMinor);

            $apexOptions = $this->buildApexOptions($baseline, $effectiveBufferMinor, $yMin, $yMax, '#0F172A');
            $chartElementId = 'forecast-chart-baseline-'.$selectedAccountId;

            if ($scenario !== null) {
                $scenarioApexOptions = $this->buildApexOptions($scenario, $effectiveBufferMinor, $yMin, $yMax, $scenarioPanelColor);
                $scenarioChartElementId = 'forecast-chart-scenario-'.$selectedAccountId.'-'.$this->scenarioId;
            }
        }

        if ($isAllAccountsView && ! $isEmpty) {
            [$aggregatePoints, $aggregateBufferFloor] = $this->computeAllAccountsAggregate(
                accountList: $accountList,
                horizon: $this->horizon,
                forecastQuery: $forecastQuery,
                db: $db,
                user: $user,
            );
            $aggregateChartElementId = 'forecast-chart-aggregate-'.$this->horizon;
        }

        // Clock is kept on the render() signature for a future explicit
        // "as of" badge; the current surface does not render one yet.
        $now = $clock->now();
        unset($now);

        $view = $views->make('forecasting::livewire.forecast-page', [
            'accounts' => $accountList,
            'selectedAccountId' => $selectedAccountId,
            'selectedAccountName' => $selectedAccountName,
            'selectedAccountCurrency' => $defaultCurrency,
            'baseline' => $baseline,
            'apexOptions' => $apexOptions,
            'chartElementId' => $chartElementId,
            'scenario' => $scenario,
            'scenarioApexOptions' => $scenarioApexOptions,
            'scenarioChartElementId' => $scenarioChartElementId,
            'scenarioPanelColor' => $scenarioPanelColor,
            'netDiff' => $netDiff,
            'horizon' => $this->horizon,
            'isEmpty' => $isEmpty,
            'todayBalanceMinor' => $todayBalanceMinor,
            'horizonLowMinor' => $horizonLowMinor,
            'horizonHighMinor' => $horizonHighMinor,
            'defaultCurrency' => $defaultCurrency,
            'effectiveBufferMinor' => $effectiveBufferMinor,
            'shortfallWindows' => $shortfallWindows,
            'scenarios' => $scenarios,
            'activeScenarioId' => $this->scenarioId,
            'viewByFunder' => $this->viewByFunder,
            'confirmingDeleteForScenarioId' => $this->confirmingDeleteForScenarioId,
            'creatingScenario' => $this->creatingScenario,
            'newScenarioName' => $this->newScenarioName,
            'createScenarioError' => $this->createScenarioError,
            'isAllAccountsView' => $isAllAccountsView,
            'aggregatePoints' => $aggregatePoints,
            'aggregateBufferFloor' => $aggregateBufferFloor,
            'aggregateChartElementId' => $aggregateChartElementId,
            'aggregateCurrency' => $aggregateCurrency,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Forecast · beatrax']);

        return $view;
    }

    /**
     * @link ../../../../../.docs/features/forecasting/architecture.md
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
        string $panelColor = '#0F172A',
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
