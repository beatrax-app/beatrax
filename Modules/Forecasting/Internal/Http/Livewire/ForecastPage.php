<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Brand;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Support\ForecastChartView;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Actions\DeleteScenario;
use Modules\Forecasting\Public\Dto\ScenarioDto;
use Modules\Forecasting\Public\Enums\ForecastHorizon;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use Modules\Ledger\Public\Services\BaseCurrency;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ForecastPage extends Component
{
    use CoercesScalars;
    use DispatchesToast;

    // The aggregate tab, addressed where an account id otherwise goes: a
    // string, so it can share the one $account property and the one ?account=
    // query parameter with a numeric id.
    public const string ALL_ACCOUNTS = 'all';

    #[Url(as: 'account', except: self::ALL_ACCOUNTS)]
    public string $account = self::ALL_ACCOUNTS;

    // The rail offers only ForecastHorizon::days(); this is the one it
    // opens on, named once so the property, the URL default and the fallback
    // for an unlisted ?horizon= cannot drift apart.
    private const int DEFAULT_HORIZON = ForecastHorizon::OneMonth->value;

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
        if (! in_array($days, ForecastHorizon::days(), true)) {
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

    // The chart card names the scenario it is drawing. It used to name the
    // baseline whatever was selected, so picking a scenario lit its tab and left
    // the card saying Baseline -- and on a scenario with no mutations yet the
    // heading is the only thing telling the two apart.
    /**
     * @param  iterable<int, object{id: int, name: string}>  $scenarios
     */
    private static function scenarioName(iterable $scenarios, ?int $activeScenarioId): ?string
    {
        if ($activeScenarioId === null) {
            return null;
        }

        foreach ($scenarios as $scenario) {
            if ($scenario->id === $activeScenarioId) {
                return $scenario->name;
            }
        }

        return null;
    }

    public function setScenario(int|string|null $scenarioId): void
    {
        $this->scenarioId = $scenarioId === null ? null : DerivedRowId::fromWire($scenarioId);
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
        $owns = array_any($scenarioQuery->forUser($user), fn (ScenarioDto $s): bool => $s->id === $scenarioId);
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
        ScenarioQuery $scenarioQuery,
        ViewFactory $views,
        ForecastChartView $charts,
        BaseCurrency $baseCurrency,
    ): View {
        $user = $currentUser->user();

        // setHorizon() refuses an unlisted value, but the address bar reaches the
        // property directly: ?horizon=999 rendered a 999-day projection with no
        // chip lit and no way back to a horizon the rail offers.
        if (! in_array($this->horizon, ForecastHorizon::days(), true)) {
            $this->horizon = self::DEFAULT_HORIZON;
        }

        $accountList = $charts->accountList($user);
        $selectedAccountId = $this->resolveAccount($accountList);
        $isAllAccountsView = $this->account === self::ALL_ACCOUNTS;
        $isEmpty = $accountList === [];

        $scenarios = $scenarioQuery->forUser($user);
        $this->dropUnknownScenario($scenarios);

        $viewData = array_merge(
            $charts->selectedAccount($selectedAccountId, $this->horizon, $this->scenarioId, $user, $baseCurrency->code(), $this->viewByFunder),
            $isAllAccountsView && ! $isEmpty
                ? $charts->aggregate($accountList, $this->horizon, $user, $baseCurrency->code(), $this->scenarioId, $this->viewByFunder)
                : ForecastChartView::noAggregate($baseCurrency->code()),
            [
                'accounts' => $accountList,
                'selectedAccountId' => $selectedAccountId,
                'horizon' => $this->horizon,
                'isEmpty' => $isEmpty,
                'scenarios' => $scenarios,
                'activeScenarioId' => $this->scenarioId,
                'activeScenarioName' => self::scenarioName($scenarios, $this->scenarioId),
                'viewByFunder' => $this->viewByFunder,
                'confirmingDeleteForScenarioId' => $this->confirmingDeleteForScenarioId,
                'creatingScenario' => $this->creatingScenario,
                'newScenarioName' => $this->newScenarioName,
                'createScenarioError' => $this->createScenarioError,
                'isAllAccountsView' => $isAllAccountsView,
            ],
        );

        $view = $views->make('forecasting::livewire.forecast-page', $viewData);

        $view->extends('layouts.app', ['title' => Lang::get('forecasting::forecast.page_title').Brand::TITLE_SUFFIX]);

        return $view;
    }

    /**
     * @link ../../../../../.docs/features/forecasting/url-parameters.md#one-answer-for-both-cases
     *
     * @param  list<array{id: int, name: string, default_currency: string, kind: string}>  $accountList
     */
    private function resolveAccount(array $accountList): ?int
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

        // One answer for "not yours" and "nobody's". Answering them apart made
        // the id space probeable: a 404 said the row exists somewhere and a
        // rendered page said it does not.
        $this->account = self::ALL_ACCOUNTS;

        return null;
    }

    /**
     * @param  list<ScenarioDto>  $scenarios
     */
    // A scenario deleted in another tab, one the launchpad redirected to and
    // something else then removed, and one belonging to somebody else all read
    // as the baseline. The horizon above takes the same soft reset.
    private function dropUnknownScenario(array $scenarios): void
    {
        if ($this->scenarioId === null) {
            return;
        }
        foreach ($scenarios as $s) {
            if ($s->id === $this->scenarioId) {
                return;
            }
        }

        $this->scenarioId = null;
    }
}
