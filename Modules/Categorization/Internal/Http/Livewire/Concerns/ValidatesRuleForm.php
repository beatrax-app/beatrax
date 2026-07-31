<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire\Concerns;

// The form's own validation pass, distinct from the backend action's: it
// records every row's problem on its per-row error property in a single
// sweep so the whole form surfaces its faults at once, then reports pass or
// fail. Reads and writes the RuleFormModal state it is composed into.
/**
 * @link ../../../../../../.docs/features/categorization/architecture.md
 */
trait ValidatesRuleForm
{
    // Returns the parsed priority, or null when any field failed validation —
    // every row error is recorded on its own property first so the whole form
    // surfaces its problems in one pass rather than one per submit.
    private function validatedPriority(): ?int
    {
        $trimmedPriority = trim($this->priorityInput);
        $priorityValid = preg_match('/^-?\d+$/', $trimmedPriority) === 1;
        if (! $priorityValid) {
            $this->errorPriority = 'Priority must be a whole number.';
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
            $this->errorConditions = 'Add at least one condition.';

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
            $this->errorActions = 'Add at least one action.';

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
        $isBetween = $condition['op'] === 'between';
        $value2 = $isBetween ? trim($condition['value2'] ?? '') : null;

        return match (true) {
            $value === '' => "Enter a value for condition {$position}.",
            $isBetween && $value2 === '' => "Pick a lower and upper bound for condition {$position}.",
            self::hasInvalidAmount($valueType, $value, $value2) => "Enter a valid amount for condition {$position}.",
            default => null,
        };
    }

    /**
     * @param  array{id: ?int, type: string, category_id: ?int, counterparty_id: ?int, note_text: string, note_mode: string, deduction_category_id: ?int, year_override_enabled: bool, year_override: ?int}  $action
     */
    private static function actionRowError(array $action): ?string
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
