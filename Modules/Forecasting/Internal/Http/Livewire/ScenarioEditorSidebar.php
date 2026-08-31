<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Http\Livewire\Concerns\BuildsMutationForms;
use Modules\Forecasting\Internal\Http\Livewire\Concerns\SummarisesMutations;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Actions\DeleteScenario;
use Modules\Forecasting\Public\Actions\EditScenarioMutation;
use Modules\Forecasting\Public\Actions\RemoveScenarioMutation;
use Modules\Forecasting\Public\Actions\RenameScenario;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ScenarioEditorSidebar extends Component
{
    use BuildsMutationForms;
    use DispatchesToast;
    use SummarisesMutations;

    // Locked because deleteScenario() takes no id parameter: it acts on this
    // property and never compares it to $confirmingDeleteScenario. Unlocked, a
    // payload naming another of the reader's own scenarios deleted that one,
    // cascaded its mutations, and toasted the name of the one still on screen.
    #[Locked]
    public int $scenarioId = 0;

    public string $scenarioName = '';

    public ?string $scenarioDescription = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $mutations = [];

    // Locked because render() is the only writer and an action method runs
    // BEFORE it: unlocked, a tampered wire payload reached currencyForSeries()
    // and chose the denomination the amount was then parsed in.
    /**
     * @var list<array{id: int, name: string, currency: string}>
     */
    #[Locked]
    public array $availableSeries = [];

    public ?int $editingMutationId = null;

    public bool $addingMutation = false;

    public ?string $selectedKind = null;

    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    public ?string $formError = null;

    public bool $renamingScenario = false;

    public string $renameInput = '';

    public ?string $renameError = null;

    public ?int $confirmingDeleteScenario = null;

    public function mount(
        int $scenarioId,
        CurrentUser $currentUser,
        ScenarioQuery $scenarioQuery,
        RecurringSeriesQuery $seriesQuery,
    ): void {
        $user = $currentUser->user();
        $dto = $scenarioQuery->find($scenarioId, $user);
        if ($dto === null) {
            throw new NotFoundHttpException('Scenario not found.');
        }

        $this->scenarioId = $scenarioId;
        $this->scenarioName = $dto->name;
        $this->scenarioDescription = $dto->description;

        // Before the summaries, not after: each one is written from this list,
        // and on the first paint render() had not filled it yet -- so every
        // line named "series #7" and priced it at a hundredth of a yen.
        $this->loadAvailableSeries($seriesQuery, $user);
        $this->refreshMutations($scenarioQuery, $currentUser);
    }

    public function startAddMutation(): void
    {
        $this->addingMutation = true;
        $this->selectedKind = null;
        $this->form = [];
        $this->formError = null;
    }

    public function selectKind(string $kind, BaseCurrency $baseCurrency): void
    {
        if (ScenarioMutationKind::tryFrom($kind) === null) {
            return;
        }
        $this->selectedKind = $kind;
        $this->form = $this->defaultFormFor($kind, $baseCurrency->code());
        $this->formError = null;
    }

    public function cancelAddMutation(): void
    {
        $this->addingMutation = false;
        $this->selectedKind = null;
        $this->form = [];
        $this->formError = null;
    }

    public function saveAddMutation(
        CurrentUser $currentUser,
        AddScenarioMutation $action,
        ScenarioQuery $scenarioQuery,
        BaseCurrency $baseCurrency,
    ): void {
        $this->formError = null;
        if ($this->selectedKind === null) {
            $this->formError = Lang::get('forecasting::scenario.errors.pick_kind_first');

            return;
        }
        $payload = $this->buildPayloadFromForm($this->selectedKind, $baseCurrency->code());
        if ($payload === null) {
            return;
        }
        $refused = false;

        try {
            ($action)($this->scenarioId, $currentUser->user(), $this->selectedKind, $payload);
        } catch (NotFoundHttpException) {
            $this->formError = Lang::get('forecasting::scenario.errors.scenario_gone');
            $refused = true;
        } catch (\InvalidArgumentException $e) {
            $this->formError = $e->getMessage();
            $refused = true;
        }

        // A local flag, never a re-read of $formError: that property is public
        // and the client owns what it sends back, so a handler branching on it
        // would let the browser decide whether the write happened.
        if ($refused) {
            return;
        }

        $this->addingMutation = false;
        $this->selectedKind = null;
        $this->form = [];
        $this->refreshMutations($scenarioQuery, $currentUser);
        $this->toast(Lang::get('forecasting::scenario.toast.mutation_added'));
        $this->dispatch('scenario-mutated');
    }

    public function editMutation(int $mutationId): void
    {
        $this->editingMutationId = $mutationId;
        $this->formError = null;
        foreach ($this->mutations as $m) {
            if (($m['id'] ?? null) !== $mutationId) {
                continue;
            }
            $kind = isset($m['kind']) && is_string($m['kind']) ? $m['kind'] : null;
            if ($kind === null) {
                continue;
            }
            $this->selectedKind = $kind;
            $this->form = $this->coercePayloadForm($m['payload'] ?? null);

            return;
        }
    }

    public function cancelEditMutation(): void
    {
        $this->editingMutationId = null;
        $this->form = [];
        $this->formError = null;
    }

    public function saveEditMutation(
        CurrentUser $currentUser,
        EditScenarioMutation $action,
        ScenarioQuery $scenarioQuery,
        BaseCurrency $baseCurrency,
    ): void {
        $this->formError = null;
        if ($this->editingMutationId === null || $this->selectedKind === null) {
            return;
        }
        $payload = $this->buildPayloadFromForm($this->selectedKind, $baseCurrency->code());
        if ($payload === null) {
            return;
        }
        $refused = false;

        try {
            ($action)($this->editingMutationId, $currentUser->user(), $payload);
        } catch (NotFoundHttpException) {
            $this->formError = Lang::get('forecasting::scenario.errors.mutation_gone');
            $refused = true;
        } catch (\InvalidArgumentException $e) {
            $this->formError = $e->getMessage();
            $refused = true;
        }

        // A local flag, never a re-read of $formError: that property is public
        // and the client owns what it sends back, so a handler branching on it
        // would let the browser decide whether the write happened.
        if ($refused) {
            return;
        }

        $this->editingMutationId = null;
        $this->selectedKind = null;
        $this->form = [];
        $this->refreshMutations($scenarioQuery, $currentUser);
        $this->toast(Lang::get('forecasting::scenario.toast.mutation_updated'));
        $this->dispatch('scenario-mutated');
    }

    public function removeMutation(
        int $mutationId,
        CurrentUser $currentUser,
        RemoveScenarioMutation $action,
        ScenarioQuery $scenarioQuery,
    ): void {
        try {
            ($action)($mutationId, $currentUser->user());
        } catch (NotFoundHttpException) {
            // Already gone. A concurrent removal and a stale id reach the
            // same outcome, so the refresh below still has to run.
        }
        $this->refreshMutations($scenarioQuery, $currentUser);
        $this->toast(Lang::get('forecasting::scenario.toast.mutation_removed'));
        $this->dispatch('scenario-mutated');
    }

    public function startRename(): void
    {
        $this->renamingScenario = true;
        $this->renameInput = $this->scenarioName;
        $this->renameError = null;
    }

    public function cancelRename(): void
    {
        $this->renamingScenario = false;
        $this->renameInput = '';
        $this->renameError = null;
    }

    public function saveRename(
        CurrentUser $currentUser,
        RenameScenario $action,
        ScenarioQuery $scenarioQuery,
    ): void {
        $this->renameError = null;
        $name = trim($this->renameInput);
        if ($name === '') {
            $this->renameError = Lang::get('forecasting::scenario.errors.name_empty');

            return;
        }
        try {
            ($action)($this->scenarioId, $currentUser->user(), $name);
        } catch (\InvalidArgumentException $e) {
            $this->renameError = $e->getMessage();

            return;
        }
        $this->scenarioName = $name;
        $this->renamingScenario = false;
        $this->renameInput = '';
        $this->toast(Lang::get('forecasting::scenario.toast.renamed'));
        $this->dispatch('scenario-renamed');
    }

    public function confirmDeleteScenario(): void
    {
        $this->confirmingDeleteScenario = $this->scenarioId;
    }

    public function cancelDeleteScenario(): void
    {
        $this->confirmingDeleteScenario = null;
    }

    public function deleteScenario(
        CurrentUser $currentUser,
        DeleteScenario $action,
    ): void {
        try {
            ($action)($this->scenarioId, $currentUser->user());
        } catch (NotFoundHttpException) {
            // Already deleted, which is the asked-for outcome, so the toast
            // and cleanup below still have to run.
        }
        $this->confirmingDeleteScenario = null;
        $this->toast(Lang::get('forecasting::scenario.toast.deleted'));
        $this->dispatch('scenario-deleted', scenarioId: $this->scenarioId);
    }

    public function render(
        ViewFactory $views,
        CurrentUser $currentUser,
        RecurringSeriesQuery $seriesQuery,
        DatabaseManager $db,
        BaseCurrency $baseCurrency,
    ): View {
        $user = $currentUser->user();
        $this->loadAvailableSeries($seriesQuery, $user);

        return $views->make('forecasting::livewire.scenario-editor-sidebar', [
            'currencyOptions' => self::currencyOptions($db, $user, $baseCurrency->code()),
            'scenarioId' => $this->scenarioId,
            'scenarioName' => $this->scenarioName,
            'scenarioDescription' => $this->scenarioDescription,
            'mutations' => $this->mutations,
            'availableSeries' => $this->availableSeries,
            'addingMutation' => $this->addingMutation,
            'selectedKind' => $this->selectedKind,
            'editingMutationId' => $this->editingMutationId,
            'form' => $this->form,
            'formError' => $this->formError,
            'renamingScenario' => $this->renamingScenario,
            'renameInput' => $this->renameInput,
            'renameError' => $this->renameError,
            'confirmingDeleteScenario' => $this->confirmingDeleteScenario,
        ]);
    }

    // The codes the reader can actually be charged in. The field was free text
    // three characters wide, so 'usd' and 'ZZZ' both persisted, and the first
    // stage that could refuse them was the fold — which refuses by failing the
    // whole projection and leaving the scenario chart a flat line.
    /**
     * @return list<string>
     */
    private static function currencyOptions(DatabaseManager $db, User $user, string $baseCurrency): array
    {
        $codes = [$baseCurrency];
        foreach ($db->connection()->table('accounts')->where('user_id', $user->id)->pluck('default_currency') as $code) {
            if (is_string($code) && $code !== '' && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        sort($codes);

        return $codes;
    }

    private function loadAvailableSeries(RecurringSeriesQuery $seriesQuery, User $user): void
    {
        $this->availableSeries = [];
        foreach ($seriesQuery->allApprovedForUser($user) as $series) {
            $this->availableSeries[] = [
                'id' => $series->seriesId,
                'name' => $series->displayNameOverride ?? $series->detectedName,
                // A new amount for this series is typed at this currency's own
                // scale, which is not the reader's base currency's.
                'currency' => $series->latestAmount->currency(),
            ];
        }
    }

    private function refreshMutations(ScenarioQuery $scenarioQuery, CurrentUser $currentUser): void
    {
        $this->mutations = [];
        foreach ($scenarioQuery->mutationsFor($this->scenarioId, $currentUser->user()) as $dto) {
            $this->mutations[] = [
                'id' => $dto->id,
                'kind' => $dto->kind,
                'target_series_id' => $dto->targetSeriesId,
                'payload' => $dto->payload->toArray(),
                'summary' => $this->summaryFor($dto->kind, $dto->payload),
            ];
        }
    }
}
