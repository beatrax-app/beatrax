<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Actions\UpdateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleActionDto;
use Modules\Categorization\Public\Dto\RuleConditionDto;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Tax\Public\Services\TaxCategoryWriter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Global rule-form modal SFC. Mounted once in `app.blade.php` so the
 * `rule-form:open` Livewire event can open the modal from anywhere
 * (the `/rules` page rows + the transaction detail provenance panel
 * both dispatch the same event).
 *
 * Multi-condition / multi-action builder (13.4-UI-SPEC.md § Component
 * Contract) — the natural-language sentence aesthetic is preserved,
 * but each clause is now a repeater:
 *
 *     Match [all ▼] of the following:
 *       [merchant ▼] [contains ▼] [SPOTIFY_______] [×]
 *       + Add condition
 *     Then:
 *       [Category ▼] [Subscriptions / Streaming ▼] [×]
 *       + Add action
 *     Priority [___10___]
 *                                              [Save rule] [Cancel]
 *
 * Service collaborators arrive as parameters on action methods +
 * the render() / open() listener — never via constructor injection.
 * The strict-rules ruleset banishes constructor DI from Livewire
 * components.
 *
 * Cross-user safety: the open() listener pulls the rule via
 * CategorizationRuleQuery::findForUser which is user-scoped; a
 * foreign ruleId returns null and the modal opens in create mode
 * (no foreign rule data ever lands on the form).
 *
 * Each condition row's `field` property is a UI-level concept with
 * FIVE options (merchant/description/counterparty/amount/date) that
 * maps to the backend's (`field`, `value_type`) pair: the three
 * string fields map directly (value_type='string'); 'amount'/'date'
 * map to value_type='amount'/'date' with a placeholder DB `field`
 * value of 'merchant' (the DB column still requires a valid enum —
 * see `RuleConditionDto`'s docblock).
 */
final class RuleFormModal extends Component
{
    /** @var array<string, string> UI field option => backend value_type. */
    private const CONDITION_FIELDS = [
        'merchant' => 'string',
        'description' => 'string',
        'counterparty' => 'string',
        'amount' => 'amount',
        'date' => 'date',
    ];

    /** @var array<string, array<string, string>> value_type => (op => label). */
    private const OP_OPTIONS = [
        'string' => ['contains' => 'contains', 'equals' => 'equals', 'starts_with' => 'starts with'],
        'amount' => ['>' => 'more than', '<' => 'less than', 'between' => 'between', 'equals' => 'equals'],
        'date' => ['before' => 'before', 'after' => 'after', 'between' => 'between'],
    ];

    /** @var list<string> */
    private const VALID_ACTION_TYPES = ['category', 'counterparty', 'note', 'tax_tag'];

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

    /**
     * Always start the modal with one blank condition + one blank
     * action row (never a truly-empty repeater) — the combinator
     * toggle is spec'd as "always visible", and a blank Livewire
     * array property would otherwise leave `conditions.0.field`-style
     * nested `set()` calls constructing a partial row missing keys
     * `updated()` (and normalizeCondition/normalizeAction) assume are
     * present.
     */
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

        if ($ruleId === null) {
            $this->resetToCreateDefaults($currentUser, $db);

            return;
        }

        $existing = $query->findForUser($currentUser->user(), $ruleId);
        if ($existing === null) {
            // Foreign / missing rule -> fall back to create mode so the
            // modal never renders another user's rule values.
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

    /**
     * Livewire lifecycle hook — fires after every public-property update,
     * including nested array paths like `conditions.0.field`. Used to
     * keep a condition row's `op` valid whenever its `field` (and thus
     * its value_type) changes, and to clear a stale `value2` whenever
     * the row's `op` moves away from `between`.
     */
    public function updated(string $name, mixed $value): void
    {
        if (preg_match('/^conditions\.(\d+)\.field$/', $name, $matches) === 1) {
            $index = (int) $matches[1];
            if (! isset($this->conditions[$index])) {
                return;
            }
            $validOps = array_keys(self::operatorOptionsFor($this->conditions[$index]['field']));
            if (! in_array($this->conditions[$index]['op'], $validOps, true)) {
                $this->conditions[$index]['op'] = $validOps[0] ?? 'contains';
            }

            return;
        }

        if (preg_match('/^conditions\.(\d+)\.op$/', $name, $matches) === 1) {
            $index = (int) $matches[1];
            if (! isset($this->conditions[$index])) {
                return;
            }
            if ($this->conditions[$index]['op'] !== 'between') {
                $this->conditions[$index]['value2'] = null;
            }
        }
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
        $nextType = 'category';
        foreach (self::VALID_ACTION_TYPES as $type) {
            if (! in_array($type, $usedTypes, true)) {
                $nextType = $type;
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

        $hasErrors = false;

        $trimmedPriority = trim($this->priorityInput);
        $priority = 0;
        if (preg_match('/^-?\d+$/', $trimmedPriority) !== 1) {
            $this->errorPriority = 'Priority must be a whole number.';
            $hasErrors = true;
        } else {
            $priority = (int) $trimmedPriority;
        }

        if ($this->conditions === []) {
            $this->errorConditions = 'Add at least one condition.';
            $hasErrors = true;
        } else {
            foreach ($this->conditions as $index => $condition) {
                $message = self::conditionRowError($condition, $index);
                if ($message !== null) {
                    $this->conditionErrors[$index] = $message;
                    $hasErrors = true;
                }
            }
        }

        if ($this->actions === []) {
            $this->errorActions = 'Add at least one action.';
            $hasErrors = true;
        } else {
            foreach ($this->actions as $index => $action) {
                $message = self::actionRowError($action, $index);
                if ($message !== null) {
                    $this->actionErrors[$index] = $message;
                    $hasErrors = true;
                }
            }
        }

        if ($hasErrors) {
            return;
        }

        $conditionsPayload = array_map(self::conditionPayload(...), $this->conditions);
        $actionsPayload = [];
        foreach ($this->actions as $index => $action) {
            $actionsPayload[] = self::actionPayload($action, $index);
        }

        $user = $currentUser->user();

        try {
            if ($this->editingRuleId === null) {
                $ruleId = ($create)($user, $priority, $this->combinator, $this->active, $this->notes, $conditionsPayload, $actionsPayload);
                $action = 'created';
            } else {
                ($update)($user, $this->editingRuleId, $priority, $this->combinator, $this->active, $this->notes, $conditionsPayload, $actionsPayload);
                $ruleId = $this->editingRuleId;
                $action = 'updated';
            }
        } catch (ValidationException $e) {
            $messages = $e->errors();
            $this->errorConditions = self::firstMessage($messages, 'conditions') ?? $this->errorConditions;
            $this->errorActions = self::firstMessage($messages, 'actions') ?? $this->errorActions;
            $this->errorGeneral = self::firstMessage($messages, 'value') ?? $this->errorGeneral;

            return;
        } catch (InvalidArgumentException) {
            // CreateCategorizationRule / UpdateCategorizationRule throw
            // InvalidArgumentException for an out-of-whitelist field/op/
            // value_type OR for an action-payload id (category/
            // counterparty/deduction category) not visible to the
            // caller. All causes are tampered-payload-only — the form's
            // dropdowns can only emit valid options. Surface a calm
            // copy and let the user retry.
            $this->errorGeneral = 'Invalid rule data — pick from the dropdowns and try again.';

            return;
        } catch (NotFoundHttpException) {
            // UpdateCategorizationRule throws this when $editingRuleId
            // no longer maps to a row visible to the user (deleted in
            // another tab, or a tampered ruleId). Close the modal so
            // the page re-renders without it.
            $this->errorGeneral = 'That rule is no longer available.';
            $this->dispatch('modal-close', name: 'rule-form');

            return;
        }

        $this->dispatch('rule-form:saved', ruleId: $ruleId, action: $action);
        $this->dispatch('modal-close', name: 'rule-form');

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
    ): View {
        $user = $currentUser->user();

        $counterparties = $db->connection()
            ->table('counterparties')
            ->where('user_id', $user->id)
            ->orderBy('display_name')
            ->get(['id', 'display_name']);

        return $views->make('categorization::livewire.rule-form-modal', [
            'categories' => $categoryOptions->for($user),
            'counterparties' => $counterparties,
            'deductionCategories' => $taxCategories->listForUser($user->id),
            'isEditMode' => $this->editingRuleId !== null,
            'usedActionTypes' => array_column($this->actions, 'type'),
        ]);
    }

    /** @return array<string, string> */
    public static function operatorOptionsFor(string $field): array
    {
        return self::OP_OPTIONS[self::valueTypeFor($field)];
    }

    public static function valueTypeFor(string $field): string
    {
        return self::CONDITION_FIELDS[$field] ?? 'string';
    }

    /**
     * @return array{id: ?int, field: string, op: string, value: string, value2: ?string}
     */
    private static function blankCondition(): array
    {
        return [
            'id' => null,
            'field' => 'merchant',
            'op' => 'contains',
            'value' => '',
            'value2' => null,
        ];
    }

    /**
     * @return array{id: ?int, type: string, category_id: ?int, counterparty_id: ?int, note_text: string, note_mode: string, deduction_category_id: ?int, year_override_enabled: bool, year_override: ?int}
     */
    private static function blankAction(string $type): array
    {
        return [
            'id' => null,
            'type' => $type,
            'category_id' => null,
            'counterparty_id' => null,
            'note_text' => '',
            'note_mode' => 'set',
            'deduction_category_id' => null,
            'year_override_enabled' => false,
            'year_override' => null,
        ];
    }

    /**
     * @return array{id: ?int, field: string, op: string, value: string, value2: ?string}
     */
    private static function conditionFromDto(RuleConditionDto $dto): array
    {
        $field = $dto->valueType === 'string' ? $dto->field : $dto->valueType;

        if ($dto->valueType === 'amount') {
            // The DB stores signed integer minor units (CR-01); the form
            // input displays the project's Dutch-decimal Euro convention,
            // so round-trip through formatAmount() rather than showing
            // the raw minor-unit string back to the user.
            $value = is_numeric($dto->value) ? self::formatAmount((int) $dto->value) : $dto->value;
            $value2 = $dto->value2 !== null && is_numeric($dto->value2) ? self::formatAmount((int) $dto->value2) : $dto->value2;
        } else {
            $value = $dto->value;
            $value2 = $dto->value2;
        }

        return [
            'id' => $dto->id,
            'field' => $field,
            'op' => $dto->op,
            'value' => $value,
            'value2' => $value2,
        ];
    }

    /**
     * @return array{id: ?int, type: string, category_id: ?int, counterparty_id: ?int, note_text: string, note_mode: string, deduction_category_id: ?int, year_override_enabled: bool, year_override: ?int}
     */
    private static function actionFromDto(RuleActionDto $dto): array
    {
        $row = self::blankAction($dto->type);
        $row['id'] = $dto->id;
        $payload = $dto->payload;

        if ($dto->type === 'category') {
            $row['category_id'] = isset($payload['category_id']) && is_numeric($payload['category_id']) ? (int) $payload['category_id'] : null;
        } elseif ($dto->type === 'counterparty') {
            $row['counterparty_id'] = isset($payload['counterparty_id']) && is_numeric($payload['counterparty_id']) ? (int) $payload['counterparty_id'] : null;
        } elseif ($dto->type === 'note') {
            $row['note_text'] = isset($payload['text']) && is_string($payload['text']) ? $payload['text'] : '';
            $row['note_mode'] = isset($payload['mode']) && is_string($payload['mode']) ? $payload['mode'] : 'set';
        } else {
            // 'tax_tag' — the only type left once category/counterparty/note
            // are excluded (VALID_ACTION_TYPES has exactly 4 members).
            $row['deduction_category_id'] = isset($payload['deduction_category_id']) && is_numeric($payload['deduction_category_id']) ? (int) $payload['deduction_category_id'] : null;
            if (isset($payload['year']) && is_numeric($payload['year'])) {
                $row['year_override_enabled'] = true;
                $row['year_override'] = (int) $payload['year'];
            }
        }

        return $row;
    }

    /**
     * @param  array{id: ?int, field: string, op: string, value: string, value2: ?string}  $condition
     */
    private static function conditionRowError(array $condition, int $index): ?string
    {
        $value = trim($condition['value']);
        if ($value === '') {
            return 'Enter a value for condition '.($index + 1).'.';
        }

        $valueType = self::valueTypeFor($condition['field']);

        if ($condition['op'] === 'between') {
            $value2 = trim($condition['value2'] ?? '');
            if ($value2 === '') {
                return 'Pick a lower and upper bound for condition '.($index + 1).'.';
            }
            if ($valueType === 'amount' && self::parseAmount($value2) === null) {
                return 'Enter a valid amount for condition '.($index + 1).'.';
            }
        }

        if ($valueType === 'amount' && self::parseAmount($value) === null) {
            return 'Enter a valid amount for condition '.($index + 1).'.';
        }

        return null;
    }

    /**
     * @param  array{id: ?int, type: string, category_id: ?int, counterparty_id: ?int, note_text: string, note_mode: string, deduction_category_id: ?int, year_override_enabled: bool, year_override: ?int}  $action
     */
    private static function actionRowError(array $action, int $index): ?string
    {
        return match ($action['type']) {
            'category' => self::isEmptyId($action['category_id']) ? 'Pick a category for this action.' : null,
            'counterparty' => self::isEmptyId($action['counterparty_id']) ? 'Pick a counterparty to reassign to.' : null,
            'note' => trim($action['note_text']) === '' ? 'Enter note text.' : null,
            default => self::isEmptyId($action['deduction_category_id']) ? 'Pick a deduction category for the tax tag.' : null,
        };
    }

    private static function isEmptyId(?int $value): bool
    {
        return $value === null || $value <= 0;
    }

    /**
     * Extracts the first string message for `$key` out of a
     * `ValidationException::errors()` payload (untyped `array` return
     * per the framework's own docblock — every access here is
     * defensively narrowed before use).
     *
     * @param  array<array-key, mixed>  $messages
     */
    private static function firstMessage(array $messages, string $key): ?string
    {
        $value = $messages[$key] ?? null;
        if (! is_array($value)) {
            return null;
        }
        $first = $value[0] ?? null;

        return is_string($first) ? $first : null;
    }

    /**
     * @param  array{id: ?int, field: string, op: string, value: string, value2: ?string}  $condition
     * @return array{id: ?int, field: string, op: string, value_type: string, value: string, value2: ?string}
     */
    private static function conditionPayload(array $condition): array
    {
        $valueType = self::valueTypeFor($condition['field']);
        $dbField = $valueType === 'string' ? $condition['field'] : 'merchant';

        if ($valueType === 'amount') {
            // Convert the human-entered Dutch/plain-decimal Euro string
            // (placeholder "0,00", the project's established money-input
            // convention — see TransactionDetail::parseAmount()) into
            // signed integer minor units BEFORE it is persisted, so it
            // agrees with `settledAmountMinor`/RuleEngine::toIntValue()'s
            // units (CR-01). conditionRowError() has already rejected an
            // unparsable value by the time save() reaches this method, so
            // the `?? 0` fallback below is unreachable defence-in-depth,
            // never the normal path.
            $value = (string) (self::parseAmount($condition['value']) ?? 0);
            $value2 = $condition['op'] === 'between'
                ? (string) (self::parseAmount($condition['value2'] ?? '') ?? 0)
                : null;
        } else {
            $value = trim($condition['value']);
            $value2 = $condition['op'] === 'between' ? trim($condition['value2'] ?? '') : null;
        }

        return [
            'id' => $condition['id'],
            'field' => $dbField,
            'op' => $condition['op'],
            'value_type' => $valueType,
            'value' => $value,
            'value2' => $value2,
        ];
    }

    /**
     * Parses a signed money string to minor units, or null if unparsable.
     * COPIED (adapted) from `Modules\Ledger\Internal\Http\Livewire\
     * ReconcilePage::parseAmount()` — same duplication convention as
     * every other Livewire money input in this codebase (see
     * `TransactionDetail::parseAmount()`'s docblock). Signed (allows a
     * leading `-`, zero, and negative results) because an amount
     * condition compares against `settledAmountMinor`, which is a signed
     * BIGINT (negative for expenses). Accepts plain ("12.50"), Dutch
     * grouped ("1.234,56"), and comma-decimal ("12,50") forms; the
     * rightmost of '.' or ',' is the decimal separator. Do not hand-roll
     * a new regex; keep in sync if the canonical copy ever changes.
     */
    private static function parseAmount(string $value): ?int
    {
        $trimmed = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($trimmed === '') {
            return null;
        }

        $negative = str_starts_with($trimmed, '-');
        $unsigned = $negative ? substr($trimmed, 1) : $trimmed;

        $lastDot = strrpos($unsigned, '.');
        $lastComma = strrpos($unsigned, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $unsigned = $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $unsigned)
                : str_replace(',', '', $unsigned);
        } elseif ($lastComma !== false) {
            $unsigned = str_replace(',', '.', $unsigned);
        }

        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $unsigned) !== 1) {
            return null;
        }

        [$whole, $frac] = array_pad(explode('.', $unsigned, 2), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad($frac, 2, '0');

        return $negative ? -$minor : $minor;
    }

    /**
     * Formats signed minor units back into an editable Dutch-decimal
     * string (e.g. `-5000` -> `"-50,00"`) so an amount condition loaded
     * via `conditionFromDto()` round-trips through `parseAmount()`
     * unchanged if the user submits it untouched (mirrors
     * `ReconcilePage::formatMinorForInput()`).
     */
    private static function formatAmount(int $minor): string
    {
        $negative = $minor < 0;
        $abs = abs($minor);
        $whole = intdiv($abs, 100);
        $cents = $abs % 100;

        return ($negative ? '-' : '').$whole.','.str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array{id: ?int, type: string, category_id: ?int, counterparty_id: ?int, note_text: string, note_mode: string, deduction_category_id: ?int, year_override_enabled: bool, year_override: ?int}  $action
     * @return array{id: ?int, position: int, type: string, payload: array<string, mixed>}
     */
    private static function actionPayload(array $action, int $index): array
    {
        $payload = match ($action['type']) {
            'category' => ['category_id' => $action['category_id']],
            'counterparty' => ['counterparty_id' => $action['counterparty_id']],
            'note' => ['text' => trim($action['note_text']), 'mode' => $action['note_mode']],
            default => self::taxTagPayload($action),
        };

        return [
            'id' => $action['id'],
            'position' => $index,
            'type' => $action['type'],
            'payload' => $payload,
        ];
    }

    /**
     * @param  array{id: ?int, type: string, category_id: ?int, counterparty_id: ?int, note_text: string, note_mode: string, deduction_category_id: ?int, year_override_enabled: bool, year_override: ?int}  $action
     * @return array<string, mixed>
     */
    private static function taxTagPayload(array $action): array
    {
        $payload = ['deduction_category_id' => $action['deduction_category_id']];
        if ($action['year_override_enabled'] && $action['year_override'] !== null) {
            $payload['year'] = $action['year_override'];
        }

        return $payload;
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
