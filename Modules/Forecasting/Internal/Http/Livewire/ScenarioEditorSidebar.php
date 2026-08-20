<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
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
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ScenarioEditorSidebar extends Component
{
    use BuildsMutationForms;
    use DispatchesToast;
    use SummarisesMutations;

    public int $scenarioId = 0;

    public string $scenarioName = '';

    public ?string $scenarioDescription = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $mutations = [];

    /**
     * @var list<array{id: int, name: string}>
     */
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
    ): void {
        $user = $currentUser->user();
        $dto = $scenarioQuery->find($scenarioId, $user);
        if ($dto === null) {
            throw new NotFoundHttpException('Scenario not found.');
        }

        $this->scenarioId = $scenarioId;
        $this->scenarioName = $dto->name;
        $this->scenarioDescription = $dto->description;

        $this->refreshMutations($scenarioQuery, $currentUser);
    }

    public function startAddMutation(): void
    {
        $this->addingMutation = true;
        $this->selectedKind = null;
        $this->form = [];
        $this->formError = null;
    }

    public function selectKind(string $kind): void
    {
        if (ScenarioMutationKind::tryFrom($kind) === null) {
            return;
        }
        $this->selectedKind = $kind;
        $this->form = $this->defaultFormFor($kind);
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
    ): void {
        $this->formError = null;
        if ($this->selectedKind === null) {
            $this->formError = Lang::get('forecasting::scenario.errors.pick_kind_first');

            return;
        }
        $payload = $this->buildPayloadFromForm($this->selectedKind);
        if ($payload === null) {
            return;
        }
        try {
            ($action)($this->scenarioId, $currentUser->user(), $this->selectedKind, $payload);
        } catch (\InvalidArgumentException|NotFoundHttpException $e) {
            $this->formError = $e->getMessage();

            return;
        }
        $this->addingMutation = false;
        $this->selectedKind = null;
        $this->form = [];
        $this->refreshMutations($scenarioQuery, $currentUser);
        $this->toast(Lang::get('forecasting::scenario.toast.mutation_added'));
        $this->dispatch('forecast-updated');
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
    ): void {
        $this->formError = null;
        if ($this->editingMutationId === null || $this->selectedKind === null) {
            return;
        }
        $payload = $this->buildPayloadFromForm($this->selectedKind);
        if ($payload === null) {
            return;
        }
        try {
            ($action)($this->editingMutationId, $currentUser->user(), $payload);
        } catch (\InvalidArgumentException|NotFoundHttpException $e) {
            $this->formError = $e->getMessage();

            return;
        }
        $this->editingMutationId = null;
        $this->selectedKind = null;
        $this->form = [];
        $this->refreshMutations($scenarioQuery, $currentUser);
        $this->toast(Lang::get('forecasting::scenario.toast.mutation_updated'));
        $this->dispatch('forecast-updated');
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
            // Already gone — a concurrent removal or a stale mutation id
            // both resolve to the same "nothing left to remove" outcome,
            // so the not-found is swallowed and the list still refreshes.
        }
        $this->refreshMutations($scenarioQuery, $currentUser);
        $this->toast(Lang::get('forecasting::scenario.toast.mutation_removed'));
        $this->dispatch('forecast-updated');
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
        $this->dispatch('forecast-updated');
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
            // Already deleted — treat a not-found scenario as success so
            // the toast, event dispatch and cleanup below still run for
            // the stale-id case.
        }
        $this->confirmingDeleteScenario = null;
        $this->toast(Lang::get('forecasting::scenario.toast.deleted'));
        $this->dispatch('scenario-deleted', scenarioId: $this->scenarioId);
        $this->dispatch('forecast-updated');
    }

    public function render(
        ViewFactory $views,
        CurrentUser $currentUser,
        RecurringSeriesQuery $seriesQuery,
    ): View {
        $this->availableSeries = [];
        foreach ($seriesQuery->allApprovedForUser($currentUser->user()) as $s) {
            $this->availableSeries[] = [
                'id' => $s->seriesId,
                'name' => $s->displayNameOverride ?? $s->detectedName,
            ];
        }

        return $views->make('forecasting::livewire.scenario-editor-sidebar', [
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
