<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Categorization\Internal\Http\Livewire\Concerns\MapsRuleRows;
use Modules\Categorization\Internal\Http\Livewire\Concerns\ValidatesRuleForm;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Actions\UpdateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Categorization\Public\Enums\ActionType;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Services\TaxCategoryWriter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Global rule-form modal SFC, mounted once in app.blade.php. The open()
// listener pulls the rule via CategorizationRuleQuery::findForUser
// (user-scoped); a foreign ruleId falls back to create mode. Each
// condition row's UI `field` maps to the backend (field, value_type) pair.
final class RuleFormModal extends Component
{
    // Stateless UI<->backend row mapping (fields, factories, DTO round-trips,
    // amount parsing) and the form's own row validation, each its own concern.
    use MapsRuleRows;
    use ValidatesRuleForm;

    public ?int $editingRuleId = null;

    public string $combinator = 'all';

    public string $priorityInput = '10';

    public bool $active = true;

    public ?string $notes = null;

    /**
     * @var list<array{id: ?int, field: string, op: string, value: string, value2: ?string}>
     */
    public array $conditions = [];

    /**
     * @var list<array{id: ?int, type: string, category_id: ?int, counterparty_id: ?int, note_text: string, note_mode: string, deduction_category_id: ?int, year_override_enabled: bool, year_override: ?int}>
     */
    public array $actions = [];

    public string $errorConditions = '';

    public string $errorActions = '';

    public string $errorPriority = '';

    public string $errorGeneral = '';

    /** @var array<int, string> condition row index => error message */
    public array $conditionErrors = [];

    /** @var array<int, string> action row index => error message */
    public array $actionErrors = [];

    // Always start with one blank condition + one blank action row —
    // never a truly-empty repeater, since a blank array property would
    // let `conditions.0.field`-style nested set() calls construct a
    // partial row missing keys updated() assumes are present.
    public function mount(CurrentUser $currentUser, DatabaseManager $db): void
    {
        $this->resetToCreateDefaults($currentUser, $db);
    }

    #[On('rule-form:open')]
    public function open(
        CurrentUser $currentUser,
        CategorizationRuleQuery $query,
        DatabaseManager $db,
        ?int $ruleId = null,
    ): void {
        $this->resetErrors();

        // Dispatched before the hydration branches below so all paths
        // (create, foreign-fallback, edit) reliably show the dialog.
        $this->dispatch('modal-show', name: 'rule-form');

        if ($ruleId === null) {
            $this->resetToCreateDefaults($currentUser, $db);

            return;
        }

        $existing = $query->findForUser($currentUser->user(), $ruleId);
        if ($existing === null) {
            $this->resetToCreateDefaults($currentUser, $db);

            return;
        }

        $this->editingRuleId = $existing->id;
        $this->combinator = $existing->combinator;
        $this->priorityInput = (string) $existing->priority;
        $this->active = $existing->active;
        $this->notes = $existing->notes;
        $this->conditions = array_map(self::conditionFromDto(...), $existing->conditions);
        $this->actions = array_map(self::actionFromDto(...), $existing->actions);
    }

    // Keeps a condition row's `op` valid whenever its `field` (and thus
    // its value_type) changes, and clears a stale `value2` whenever the
    // row's `op` moves away from `between`.
    public function updated(string $name): void
    {
        if (preg_match('/^conditions\.(\d+)\.field$/', $name, $matches) === 1) {
            $this->realignConditionOp((int) $matches[1]);
        } elseif (preg_match('/^conditions\.(\d+)\.op$/', $name, $matches) === 1) {
            $this->clearStaleUpperBound((int) $matches[1]);
        } elseif (preg_match('/^actions\.(\d+)\./', $name, $matches) === 1) {
            $this->coerceActionIds((int) $matches[1]);
        }
    }

    // A changed `field` can invalidate the row's `op` (its value_type moved),
    // so snap `op` back to the first operator the new field allows.
    private function realignConditionOp(int $index): void
    {
        if (! isset($this->conditions[$index])) {
            return;
        }
        $validOps = array_keys(self::operatorOptionsFor($this->conditions[$index]['field']));
        if (! in_array($this->conditions[$index]['op'], $validOps, true)) {
            $this->conditions[$index]['op'] = $validOps[0] ?? 'contains';
        }
    }

    // An `op` that moved away from `between` leaves a stale upper bound behind,
    // so drop the now-meaningless value2 the row still carries.
    private function clearStaleUpperBound(int $index): void
    {
        if (isset($this->conditions[$index]) && $this->conditions[$index]['op'] !== 'between') {
            $this->conditions[$index]['value2'] = null;
        }
    }

    // Livewire binds <select> values as strings; re-coerce to ?int after each
    // update, otherwise a picked id arrives as "20" and blows up
    // actionRowError().
    private function coerceActionIds(int $index): void
    {
        if (! isset($this->actions[$index])) {
            return;
        }
        $this->actions[$index]['category_id'] = self::intIdOrNull($this->actions[$index]['category_id']);
        $this->actions[$index]['counterparty_id'] = self::intIdOrNull($this->actions[$index]['counterparty_id']);
        $this->actions[$index]['deduction_category_id'] = self::intIdOrNull($this->actions[$index]['deduction_category_id']);
        $this->actions[$index]['year_override'] = self::intIdOrNull($this->actions[$index]['year_override']);
    }

    public function addCondition(): void
    {
        $this->conditions[] = self::blankCondition();
    }

    public function removeCondition(int $index): void
    {
        if (count($this->conditions) <= 1) {
            return;
        }
        $remaining = [];
        foreach ($this->conditions as $i => $condition) {
            if ($i !== $index) {
                $remaining[] = $condition;
            }
        }
        $this->conditions = $remaining;
    }

    public function addAction(): void
    {
        $usedTypes = array_column($this->actions, 'type');
        $nextType = ActionType::Category->value;
        foreach (ActionType::cases() as $type) {
            if (! in_array($type->value, $usedTypes, true)) {
                $nextType = $type->value;
                break;
            }
        }
        $this->actions[] = self::blankAction($nextType);
    }

    public function removeAction(int $index): void
    {
        if (count($this->actions) <= 1) {
            return;
        }
        $remaining = [];
        foreach ($this->actions as $i => $action) {
            if ($i !== $index) {
                $remaining[] = $action;
            }
        }
        $this->actions = $remaining;
    }

    public function save(
        CurrentUser $currentUser,
        CreateCategorizationRule $create,
        UpdateCategorizationRule $update,
    ): void {
        $this->resetErrors();

        $priority = $this->validatedPriority();
        if ($priority === null) {
            return;
        }

        // Capture the create-vs-update outcome before persist() runs, since a
        // successful persist resets editingRuleId to null.
        $action = $this->editingRuleId === null ? 'created' : 'updated';
        $ruleId = $this->persist($currentUser, $priority, $create, $update);
        if ($ruleId === null) {
            return;
        }

        $this->dispatch('rule-form:saved', ruleId: $ruleId, action: $action);
        $this->dispatch('modal-close', name: 'rule-form');
        $this->resetToBlankForm();
    }

    // Both actions return the rule id, so the create/update choice is one
    // return; the three failure types share one catch that maps each to its
    // own message. Returns null on any failure, having set the error state.
    private function persist(
        CurrentUser $currentUser,
        int $priority,
        CreateCategorizationRule $create,
        UpdateCategorizationRule $update,
    ): ?int {
        $conditionsPayload = array_map(self::conditionPayload(...), $this->conditions);
        $actionsPayload = [];
        foreach ($this->actions as $index => $action) {
            $actionsPayload[] = self::actionPayload($action, $index);
        }

        $user = $currentUser->user();
        $input = new RuleInput(
            priority: $priority,
            combinator: $this->combinator,
            active: $this->active,
            notes: $this->notes,
            conditions: $conditionsPayload,
            actions: $actionsPayload,
        );

        try {
            return $this->editingRuleId === null
                ? ($create)($user, $input)
                : ($update)($user, $this->editingRuleId, $input);
        } catch (ValidationException|InvalidArgumentException|NotFoundHttpException $e) {
            $this->applyPersistError($e);

            return null;
        }
    }

    private function applyPersistError(ValidationException|InvalidArgumentException|NotFoundHttpException $e): void
    {
        if ($e instanceof ValidationException) {
            $messages = $e->errors();
            $this->errorConditions = self::firstMessage($messages, 'conditions') ?? $this->errorConditions;
            $this->errorActions = self::firstMessage($messages, 'actions') ?? $this->errorActions;
            $this->errorGeneral = self::firstMessage($messages, 'value') ?? $this->errorGeneral;

            return;
        }

        if ($e instanceof NotFoundHttpException) {
            // $editingRuleId no longer maps to a row visible to the user
            // (deleted in another tab, or a tampered ruleId) — close the modal.
            $this->errorGeneral = Lang::get('categorization::rule_form.error_rule_unavailable');
            $this->dispatch('modal-close', name: 'rule-form');

            return;
        }

        // InvalidArgumentException: every cause is tampered-payload-only — the
        // form's own dropdowns can only emit valid options.
        $this->errorGeneral = Lang::get('categorization::rule_form.error_invalid_data');
    }

    private function resetToBlankForm(): void
    {
        $this->editingRuleId = null;
        $this->combinator = 'all';
        $this->priorityInput = '10';
        $this->active = true;
        $this->notes = null;
        $this->conditions = [self::blankCondition()];
        $this->actions = [self::blankAction('category')];
    }

    public function cancel(): void
    {
        $this->dispatch('modal-close', name: 'rule-form');
    }

    public function render(
        CurrentUser $currentUser,
        CategoryOptionsQuery $categoryOptions,
        TaxCategoryWriter $taxCategories,
        DatabaseManager $db,
        ViewFactory $views,
        SensitiveColumnCodec $codec,
        Session $session,
    ): View {
        $user = $currentUser->user();

        // SQL order over ciphertext is meaningless once encryption is
        // enabled; a stable orderBy('id') keeps row iteration
        // deterministic, and the real user-facing order is the
        // post-decrypt sortBy() below.
        $counterparties = $db->connection()
            ->table('counterparties')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'display_name'])
            ->map(function (object $row) use ($codec, $session, $user): object {
                $stored = is_string($row->display_name ?? null) ? $row->display_name : '';
                $row->display_name = $stored === ''
                    ? ''
                    : $codec->decryptValue('counterparties', 'display_name', $stored, $user->id, $session)['value'];

                return $row;
            })
            ->sortBy(static fn (object $row): string => is_string($row->display_name ?? null) ? $row->display_name : '')
            ->values();

        return $views->make('categorization::livewire.rule-form-modal', [
            'categories' => $categoryOptions->for($user),
            'counterparties' => $counterparties,
            'deductionCategories' => $taxCategories->listForUser($user->id),
            'isEditMode' => $this->editingRuleId !== null,
            'usedActionTypes' => array_column($this->actions, 'type'),
        ]);
    }

    private function resetErrors(): void
    {
        $this->errorConditions = '';
        $this->errorActions = '';
        $this->errorPriority = '';
        $this->errorGeneral = '';
        $this->conditionErrors = [];
        $this->actionErrors = [];
    }

    private function resetToCreateDefaults(CurrentUser $currentUser, DatabaseManager $db): void
    {
        $this->editingRuleId = null;
        $this->combinator = 'all';
        $this->active = true;
        $this->notes = null;
        $this->conditions = [self::blankCondition()];
        $this->actions = [self::blankAction('category')];

        $maxPriority = $db->connection()
            ->table('categorization_rules')
            ->where('user_id', $currentUser->user()->id)
            ->max('priority');

        $this->priorityInput = (string) ((is_numeric($maxPriority) ? (int) $maxPriority : 0) + 10);
    }
}
