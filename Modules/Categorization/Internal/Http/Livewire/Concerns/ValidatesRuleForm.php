<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire\Concerns;

use Modules\Categorization\Public\Enums\ActionType;
use Modules\Categorization\Public\Enums\ConditionOperator;
use Modules\Core\Public\Support\Lang;

// The form's own pass, distinct from the backend action's: it records every
// row's problem on that row's error property in one sweep, so a submit surfaces
// the whole form's faults rather than one per attempt.
trait ValidatesRuleForm
{
    private function validatedPriority(): ?int
    {
        $trimmedPriority = trim($this->priorityInput);
        $priorityValid = preg_match('/^-?\d+$/', $trimmedPriority) === 1;
        if (! $priorityValid) {
            $this->errorPriority = Lang::get('categorization::rule_form.error_priority_whole');
        }

        $conditionsInvalid = $this->collectConditionErrors();
        $actionsInvalid = $this->collectActionErrors();

        if (! $priorityValid || $conditionsInvalid || $actionsInvalid) {
            return null;
        }

        return (int) $trimmedPriority;
    }

    private function collectConditionErrors(): bool
    {
        if ($this->conditions === []) {
            $this->errorConditions = Lang::get('categorization::rule_form.error_add_condition');

            return true;
        }

        $hasErrors = false;
        foreach ($this->conditions as $index => $condition) {
            $message = self::conditionRowError($condition, $index);
            if ($message !== null) {
                $this->conditionErrors[$index] = $message;
                $hasErrors = true;
            }
        }

        return $hasErrors;
    }

    private function collectActionErrors(): bool
    {
        if ($this->actions === []) {
            $this->errorActions = Lang::get('categorization::rule_form.error_add_action');

            return true;
        }

        $hasErrors = false;
        foreach ($this->actions as $index => $action) {
            $message = self::actionRowError($action);
            if ($message !== null) {
                $this->actionErrors[$index] = $message;
                $hasErrors = true;
            }
        }

        return $hasErrors;
    }

    /**
     * @param  array{id: ?int, field: string, op: string, value: string, value2: ?string}  $condition
     */
    private static function conditionRowError(array $condition, int $index): ?string
    {
        $position = $index + 1;
        $value = trim($condition['value']);
        $valueType = self::valueTypeFor($condition['field']);
        $isBetween = $condition['op'] === ConditionOperator::Between->value;
        $value2 = $isBetween ? trim($condition['value2'] ?? '') : null;

        return match (true) {
            $value === '' => Lang::get('categorization::rule_form.condition_value_required', ['position' => $position]),
            $isBetween && $value2 === '' => Lang::get('categorization::rule_form.condition_bounds_required', ['position' => $position]),
            self::hasInvalidAmount($valueType, $value, $value2) => Lang::get('categorization::rule_form.condition_amount_invalid', ['position' => $position]),
            default => null,
        };
    }

    /**
     * @param  array{id: ?int, type: string, category_id: ?int, counterparty_id: ?int, note_text: string, note_mode: string, deduction_category_id: ?int, year_override_enabled: bool, year_override: ?int}  $action
     */
    private static function actionRowError(array $action): ?string
    {
        return match ($action['type']) {
            ActionType::Category->value => self::isEmptyId($action['category_id']) ? Lang::get('categorization::rule_form.action_pick_category') : null,
            ActionType::Counterparty->value => self::isEmptyId($action['counterparty_id']) ? Lang::get('categorization::rule_form.action_pick_counterparty') : null,
            ActionType::Note->value => trim($action['note_text']) === '' ? Lang::get('categorization::rule_form.action_note_required') : null,
            default => self::isEmptyId($action['deduction_category_id']) ? Lang::get('categorization::rule_form.action_pick_deduction') : null,
        };
    }

    private static function isEmptyId(?int $value): bool
    {
        return $value === null || $value <= 0;
    }

    // ValidationException::errors() returns an untyped array per the
    // framework's own docblock — every access here is defensively
    // narrowed before use.
    /**
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
}
