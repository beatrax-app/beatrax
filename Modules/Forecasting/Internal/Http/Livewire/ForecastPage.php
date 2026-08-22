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
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Http\Livewire\Concerns\BuildsForecastCharts;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Internal\Mapping\ForecastDtoMapper;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Actions\DeleteScenario;
use Modules\Forecasting\Public\Dto\ForecastPointDto;
use Modules\Forecasting\Public\Dto\ScenarioDto;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ForecastPage extends Component
{
    use BuildsForecastCharts;
    use CoercesScalars;
    use DispatchesToast;

    // The aggregate tab, addressed where an account id otherwise goes: a
    // string, so it can share the one $account property and the one ?account=
    // query parameter with a numeric id.
    public const string ALL_ACCOUNTS = 'all';

    #[Url(as: 'account', except: self::ALL_ACCOUNTS)]
    public string $account = self::ALL_ACCOUNTS;

    // The rail offers only ProjectForecastJob::HORIZON_DAYS; this is the one it
    // opens on, named once so the property, the URL default and the fallback
    // for an unlisted ?horizon= cannot drift apart.
    private const DEFAULT_HORIZON = 30;

    #[Url(as: 'horizon', except: self::DEFAULT_HORIZON)]
    public int $horizon = self::DEFAULT_HORIZON;

    #[Url(as: 'scenarioId', except: null)]
    public ?int $scenarioId = null;

    #[Url(as: 'viewByFunder', except: false)]
    public bool $viewByFunder = false;

    public ?int $confirmingDeleteForScenarioId = null;

    public bool $creatingScenario = false;

    public string $newScenarioName = '';

    public ?string $createScenarioError = null;

    protected $listeners = [
        'buffer-editor:saved' => 'onBufferSaved',
        'scenario-mutated' => 'onScenarioMutated',
        'scenario-renamed' => 'onScenarioMutated',
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
            $this->createScenarioError = Lang::get('forecasting::scenario.errors.name_empty');

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
        $this->toast(Lang::get('forecasting::scenario.toast.created', ['name' => $name]));
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
        // $scenarioId is browser-supplied. Re-checking it against the user's
        // own set 404s a foreign id without confirming it exists.
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
        $this->toast(Lang::get('forecasting::scenario.toast.deleted'));
        $this->dispatch('forecast-updated');
    }

    public function onBufferSaved(): void
    {
        $this->dispatch('forecast-updated');
    }

    // The sidebar is a sibling component, so its own re-render leaves this
    // page's scenario chips and chart payload untouched. Handling the event
    // re-renders both, and the charts read the fresh data-options attribute
    // off the forecast-updated that follows.
    public function onScenarioMutated(): void
    {
        $this->dispatch('forecast-updated');
    }

    public function onScenarioDeleted(): void
    {
        $this->scenarioId = null;
        $this->dispatch('forecast-updated');
    }

    public function refreshProjectionStatus(): void
    {
        // The poll ends itself: once the projection completes, the next render
        // drops the conditional wire:poll element that was calling this.
        $this->dispatch('forecast-updated');
    }

    public function render(
        CurrentUser $currentUser,
        ForecastQuery $forecastQuery,
        ScenarioQuery $scenarioQuery,
        DatabaseManager $db,
        ViewFactory $views,
        ForecastDtoMapper $mapper,
    ): View {
        $user = $currentUser->user();

        // setHorizon() refuses an unlisted value, but the address bar reaches the
        // property directly: ?horizon=999 rendered a 999-day projection with no
        // chip lit and no way back to a horizon the rail offers.
        if (! in_array($this->horizon, ProjectForecastJob::HORIZON_DAYS, true)) {
            $this->horizon = self::DEFAULT_HORIZON;
        }

        $accountList = $this->resolveAccountList($db, $user);
        $selectedAccountId = $this->normaliseAndResolveAccount($accountList);
        $isAllAccountsView = $this->account === self::ALL_ACCOUNTS;
        $isEmpty = $accountList === [];

        $scenarios = $scenarioQuery->forUser($user);
        $this->assertScenarioOwnership($scenarios);

        $viewData = array_merge(
            $this->selectedAccountView($selectedAccountId, $forecastQuery, $db, $user, $mapper),
            $this->aggregateView($accountList, $isAllAccountsView, $isEmpty, $forecastQuery, $db, $user),
            [
                'accounts' => $accountList,
                'selectedAccountId' => $selectedAccountId,
                'horizon' => $this->horizon,
                'isEmpty' => $isEmpty,
                'scenarios' => $scenarios,
                'activeScenarioId' => $this->scenarioId,
                'viewByFunder' => $this->viewByFunder,
                'confirmingDeleteForScenarioId' => $this->confirmingDeleteForScenarioId,
                'creatingScenario' => $this->creatingScenario,
                'newScenarioName' => $this->newScenarioName,
                'createScenarioError' => $this->createScenarioError,
                'isAllAccountsView' => $isAllAccountsView,
            ],
        );

        $view = $views->make('forecasting::livewire.forecast-page', $viewData);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('forecasting::forecast.page_title').' · Beatrax']);

        return $view;
    }

    /**
     * @return list<array{id: int, name: string, default_currency: string, kind: string}>
     */
    private function resolveAccountList(DatabaseManager $db, User $user): array
    {
        $accounts = $db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'default_currency', 'kind']);

        $accountList = [];
        foreach ($accounts as $account) {
            /** @var stdClass $account */
            $accountList[] = [
                'id' => self::toInt($account->id),
                'name' => self::toString($account->name ?? null),
                'default_currency' => self::toString($account->default_currency ?? null),
                'kind' => self::toString($account->kind ?? null),
            ];
        }

        return $accountList;
    }

    /**
     * @param  list<array{id: int, name: string, default_currency: string, kind: string}>  $accountList
     */
    private function normaliseAndResolveAccount(array $accountList): ?int
    {
        // A tampered or stale ?account= falls back to the aggregate tab rather
        // than rendering a blank page with no error.
        if ($this->account !== self::ALL_ACCOUNTS && ! is_numeric($this->account)) {
            $this->account = self::ALL_ACCOUNTS;
        }
        if ($this->account === self::ALL_ACCOUNTS) {
            return null;
        }

        $selectedAccountId = (int) $this->account;
        foreach ($accountList as $a) {
            if ($a['id'] === $selectedAccountId) {
                return $selectedAccountId;
            }
        }

        throw new NotFoundHttpException('Account not found.');
    }

    /**
     * @param  list<ScenarioDto>  $scenarios
     */
    private function assertScenarioOwnership(array $scenarios): void
    {
        if ($this->scenarioId === null) {
            return;
        }
        foreach ($scenarios as $s) {
            if ($s->id === $this->scenarioId) {
                return;
            }
        }

        throw new NotFoundHttpException('Scenario not found.');
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedAccountView(
        ?int $selectedAccountId,
        ForecastQuery $forecastQuery,
        DatabaseManager $db,
        User $user,
        ForecastDtoMapper $mapper,
    ): array {
        /** @var array<int, int> $netDiff */
        $netDiff = [];
        foreach (ProjectForecastJob::HORIZON_DAYS as $horizonKey) {
            $netDiff[$horizonKey] = 0;
        }

        $defaults = [
            'selectedAccountName' => '',
            'selectedAccountCurrency' => 'EUR',
            'baseline' => null,
            'apexOptions' => null,
            'chartElementId' => null,
            'scenario' => null,
            'scenarioApexOptions' => null,
            'scenarioChartElementId' => null,
            'scenarioPanelColor' => self::NEUTRAL_INK,
            'netDiff' => $netDiff,
            'todayBalanceMinor' => 0,
            'horizonLowMinor' => 0,
            'horizonHighMinor' => 0,
            'defaultCurrency' => 'EUR',
            'effectiveBufferMinor' => null,
            'shortfallWindows' => [],
        ];
        if ($selectedAccountId === null) {
            return $defaults;
        }

        $baseline = $forecastQuery->forUser($selectedAccountId, $this->horizon, null, $user);
        $lastPoint = $baseline->points === [] ? null : $baseline->points[count($baseline->points) - 1];
        $horizonLowMinor = $lastPoint instanceof ForecastPointDto ? $lastPoint->lowMinor : 0;
        $horizonHighMinor = $lastPoint instanceof ForecastPointDto ? $lastPoint->highMinor : 0;

        $accountRow = $db->connection()->table('accounts')
            ->where('id', $selectedAccountId)
            ->where('user_id', $user->id)
            ->first(['forecast_min_buffer_minor']);
        $effectiveBufferMinor = $accountRow !== null && isset($accountRow->forecast_min_buffer_minor) && is_numeric($accountRow->forecast_min_buffer_minor)
            ? (int) $accountRow->forecast_min_buffer_minor
            : null;

        $windowRows = $db->connection()->table('forecast_shortfall_windows')
            ->where('user_id', $user->id)
            ->where('account_id', $selectedAccountId)
            ->whereNull('scenario_id')
            ->orderBy('starts_at')
            ->get();
        $shortfallWindows = [];
        foreach ($windowRows as $row) {
            /** @var stdClass $row */
            $shortfallWindows[] = $mapper->mapShortfallWindow($row);
        }

        $scenario = null;
        $scenarioPanelColor = self::NEUTRAL_INK;
        if ($this->scenarioId !== null) {
            $scenario = $forecastQuery->forUser($selectedAccountId, $this->horizon, $this->scenarioId, $user);
            $netDiff = $this->computeNetDiff($baseline, $scenario);
            $scenarioPanelColor = $this->panelColorFor($netDiff[30]);
        }

        [$yMin, $yMax] = $this->computeSharedYAxisRange($baseline, $scenario, $effectiveBufferMinor);

        $scenarioApexOptions = null;
        $scenarioChartElementId = null;
        if ($scenario !== null) {
            $scenarioApexOptions = $this->buildApexOptions($scenario, $effectiveBufferMinor, $yMin, $yMax, $scenarioPanelColor);
            $scenarioChartElementId = 'forecast-chart-scenario-'.$selectedAccountId.'-'.$this->scenarioId;
        }

        return [
            'selectedAccountName' => $baseline->accountName,
            'selectedAccountCurrency' => $baseline->defaultCurrency,
            'baseline' => $baseline,
            'apexOptions' => $this->buildApexOptions($baseline, $effectiveBufferMinor, $yMin, $yMax, self::NEUTRAL_INK),
            'chartElementId' => 'forecast-chart-baseline-'.$selectedAccountId,
            'scenario' => $scenario,
            'scenarioApexOptions' => $scenarioApexOptions,
            'scenarioChartElementId' => $scenarioChartElementId,
            'scenarioPanelColor' => $scenarioPanelColor,
            'netDiff' => $netDiff,
            'todayBalanceMinor' => $baseline->todayBalanceMinor,
            'horizonLowMinor' => $horizonLowMinor,
            'horizonHighMinor' => $horizonHighMinor,
            'defaultCurrency' => $baseline->defaultCurrency,
            'effectiveBufferMinor' => $effectiveBufferMinor,
            'shortfallWindows' => $shortfallWindows,
        ];
    }

    /**
     * @param  list<array{id: int, name: string, default_currency: string, kind: string}>  $accountList
     * @return array<string, mixed>
     */
    private function aggregateView(
        array $accountList,
        bool $isAllAccountsView,
        bool $isEmpty,
        ForecastQuery $forecastQuery,
        DatabaseManager $db,
        User $user,
    ): array {
        if (! $isAllAccountsView || $isEmpty) {
            return [
                'aggregatePoints' => [],
                'aggregateBufferFloor' => 0,
                'aggregateChartElementId' => null,
                'aggregateCurrency' => 'EUR',
            ];
        }

        [$aggregatePoints, $aggregateBufferFloor] = $this->computeAllAccountsAggregate(
            accountList: $accountList,
            horizon: $this->horizon,
            forecastQuery: $forecastQuery,
            db: $db,
            user: $user,
        );

        return [
            'aggregatePoints' => $aggregatePoints,
            'aggregateBufferFloor' => $aggregateBufferFloor,
            'aggregateChartElementId' => 'forecast-chart-aggregate-'.$this->horizon,
            'aggregateCurrency' => 'EUR',
        ];
    }
}
