<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire\Concerns;

use Modules\Categorization\Public\Dto\RuleActionDto;
use Modules\Categorization\Public\Dto\RuleConditionDto;
use Modules\Categorization\Public\Enums\ConditionValueType;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

trait MapsRuleRows
{
    /** @return array<string, string> */
    public static function operatorOptionsFor(string $field): array
    {
        $options = [];
        foreach (self::fieldValueType($field)->operators() as $operator) {
            $options[$operator->value] = $operator->label();
        }

        return $options;
    }

    public static function valueTypeFor(string $field): string
    {
        return self::fieldValueType($field)->value;
    }

    private static function fieldValueType(string $field): ConditionValueType
    {
        return match ($field) {
            'amount' => ConditionValueType::Amount,
            'date' => ConditionValueType::Date,
            default => ConditionValueType::Text,
        };
    }

    private static function intIdOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array{id: ?int, field: string, op: string, value: string, value2: ?string}
     */
    private static function blankCondition(): array
    {
        return [
            'id' => null,
            'field' => 'counterparty',
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

        // `merchant` and `counterparty` are one field under two names and the
        // select no longer offers both, so a rule still stored as `merchant`
        // would display as whichever option happens to come first.
        if ($field === 'merchant') {
            $field = 'counterparty';
        }

        if ($dto->valueType === 'amount') {
            // The column holds signed minor units; the form shows Euro
            // decimals, so the raw string must never reach the input.
            $value = is_numeric($dto->value) ? MoneyInput::formatMinor((int) $dto->value) : $dto->value;
            $value2 = $dto->value2 !== null && is_numeric($dto->value2) ? MoneyInput::formatMinor((int) $dto->value2) : $dto->value2;
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
            $row['category_id'] = self::intIdOrNull($payload['category_id'] ?? null);
        } elseif ($dto->type === 'counterparty') {
            $row['counterparty_id'] = self::intIdOrNull($payload['counterparty_id'] ?? null);
        } elseif ($dto->type === 'note') {
            $noteText = $payload['text'] ?? null;
            $row['note_text'] = is_string($noteText) ? $noteText : '';
            $noteMode = $payload['mode'] ?? null;
            $row['note_mode'] = is_string($noteMode) ? $noteMode : 'set';
        } else {
            $row['deduction_category_id'] = self::intIdOrNull($payload['deduction_category_id'] ?? null);
            $year = self::intIdOrNull($payload['year'] ?? null);
            if ($year !== null) {
                $row['year_override_enabled'] = true;
                $row['year_override'] = $year;
            }
        }

        return $row;
    }

    private static function hasInvalidAmount(string $valueType, string $value, ?string $value2): bool
    {
        if ($valueType !== 'amount') {
            return false;
        }
        if ($value2 !== null && MoneyInput::tryToMinor($value2) === null) {
            return true;
        }

        return MoneyInput::tryToMinor($value) === null;
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
            // conditionRowError() has already rejected an unparsable value, so
            // the `?? 0` fallback is unreachable rather than a silent zero.
            $value = (string) (MoneyInput::tryToMinor($condition['value']) ?? 0);
            $value2 = $condition['op'] === 'between'
                ? (string) (MoneyInput::tryToMinor($condition['value2'] ?? '') ?? 0)
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
}
