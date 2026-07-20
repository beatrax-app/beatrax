<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Forecasting\Internal\Support\AmountStringParser;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Actions\DeleteScenario;
use Modules\Forecasting\Public\Actions\EditScenarioMutation;
use Modules\Forecasting\Public\Actions\RemoveScenarioMutation;
use Modules\Forecasting\Public\Actions\RenameScenario;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../../.docs/features/forecasting/architecture.md
 */
final class ScenarioEditorSidebar extends Component
{
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
        if (! in_array($kind, ['cancel_series', 'add_one_off', 'add_recurring', 'change_series_amount', 'shift_series_date'], true)) {
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
            $this->formError = 'Pick a mutation kind first.';

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
        $this->dispatch('toast', message: 'Mutation added.');
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
            $payload = $m['payload'] ?? null;
            /** @var array<string, mixed> $coerced */
            $coerced = [];
            if (is_array($payload)) {
                foreach ($payload as $k => $v) {
                    if (is_string($k)) {
                        $coerced[$k] = $v;
                    }
                }
            }
            $this->form = $coerced;

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
        $this->dispatch('toast', message: 'Mutation updated.');
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
        }
        $this->refreshMutations($scenarioQuery, $currentUser);
        $this->dispatch('toast', message: 'Mutation removed. Undo');
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
            $this->renameError = 'Scenario name cannot be empty.';

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
        $this->dispatch('toast', message: 'Scenario renamed.');
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
        }
        $this->confirmingDeleteScenario = null;
        $this->dispatch('toast', message: 'Scenario deleted.');
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

    /**
     * @return array<string, mixed>
     */
    private function defaultFormFor(string $kind): array
    {
        return match ($kind) {
            'cancel_series' => ['seriesId' => null],
            'add_one_off' => ['date' => '', 'amount' => '', 'currency' => 'EUR', 'direction' => 'expense', 'note' => ''],
            'add_recurring' => ['startDate' => '', 'amount' => '', 'currency' => 'EUR', 'direction' => 'expense', 'cadence' => 'monthly', 'note' => ''],
            'change_series_amount' => ['seriesId' => null, 'newAmount' => ''],
            'shift_series_date' => ['seriesId' => null, 'newNextDate' => '', 'scope' => 'next'],
            default => [],
        };
    }

    private function buildPayloadFromForm(string $kind): ?ScenarioMutationPayload
    {
        try {
            return match ($kind) {
                'cancel_series' => new CancelSeriesPayload(
                    seriesId: $this->intField('seriesId'),
                ),
                'add_one_off' => new AddOneOffPayload(
                    date: $this->stringField('date'),
                    amountMinor: $this->parseAmountMinor('amount'),
                    currency: $this->stringField('currency', 'EUR'),
                    direction: $this->stringField('direction', 'expense'),
                    note: $this->optionalStringField('note'),
                ),
                'add_recurring' => new AddRecurringPayload(
                    startDate: $this->stringField('startDate'),
                    amountMinor: $this->parseAmountMinor('amount'),
                    currency: $this->stringField('currency', 'EUR'),
                    direction: $this->stringField('direction', 'expense'),
                    cadence: $this->stringField('cadence', 'monthly'),
                    note: $this->optionalStringField('note'),
                ),
                'change_series_amount' => new ChangeSeriesAmountPayload(
                    seriesId: $this->intField('seriesId'),
                    newAmountMinor: $this->parseAmountMinor('newAmount'),
                ),
                'shift_series_date' => new ShiftSeriesDatePayload(
                    seriesId: $this->intField('seriesId'),
                    newNextDate: $this->stringField('newNextDate'),
                    scope: $this->stringField('scope', 'next'),
                ),
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            $this->formError = $e->getMessage();

            return null;
        }
    }

    private function intField(string $key): int
    {
        $value = $this->form[$key] ?? null;
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("Field '{$key}' is required.");
        }

        return (int) $value;
    }

    private function stringField(string $key, ?string $default = null): string
    {
        $value = $this->form[$key] ?? $default;
        if (! is_string($value) || $value === '') {
            if ($default !== null) {
                return $default;
            }
            throw new \InvalidArgumentException("Field '{$key}' is required.");
        }

        return $value;
    }

    private function optionalStringField(string $key): ?string
    {
        $value = $this->form[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    private function parseAmountMinor(string $key): int
    {
        $value = $this->form[$key] ?? null;
        if (is_string($value)) {
            $raw = $value;
        } elseif (is_numeric($value)) {
            $raw = (string) $value;
        } else {
            throw new \InvalidArgumentException('Amount is required.');
        }

        return AmountStringParser::toMinor($raw);
    }

    private function summaryFor(string $kind, ScenarioMutationPayload $payload): string
    {
        // Resolve the series display name from the catalog already
        // populated by render(); fall back to "series #N" only when the
        // series is missing from the catalog (e.g. deleted or filtered).
        $resolveName = function (int $seriesId): string {
            foreach ($this->availableSeries as $entry) {
                if ($entry['id'] === $seriesId && $entry['name'] !== '') {
                    return $entry['name'];
                }
            }

            return 'series #'.$seriesId;
        };

        if ($payload instanceof CancelSeriesPayload) {
            return 'Cancel '.$resolveName($payload->seriesId);
        }
        if ($payload instanceof AddOneOffPayload) {
            $sign = $payload->direction === 'income' ? '+' : '−';
            $amount = number_format($payload->amountMinor / 100, 2, ',', '.');

            return "{$sign}{$amount} {$payload->currency} on {$payload->date}";
        }
        if ($payload instanceof AddRecurringPayload) {
            $sign = $payload->direction === 'income' ? '+' : '−';
            $amount = number_format($payload->amountMinor / 100, 2, ',', '.');

            return "{$sign}{$amount} {$payload->currency} {$payload->cadence} from {$payload->startDate}";
        }
        if ($payload instanceof ChangeSeriesAmountPayload) {
            $amount = number_format($payload->newAmountMinor / 100, 2, ',', '.');

            return $resolveName($payload->seriesId).": new amount {$amount}";
        }
        if ($payload instanceof ShiftSeriesDatePayload) {
            $scope = $payload->scope === 'all_subsequent' ? 'all subsequent' : 'next';

            return $resolveName($payload->seriesId).": shift {$scope} to {$payload->newNextDate}";
        }

        return $kind;
    }
}
