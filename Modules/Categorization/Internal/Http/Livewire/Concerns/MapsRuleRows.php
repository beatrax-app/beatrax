<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire\Concerns;

use Modules\Categorization\Public\Dto\RuleActionDto;
use Modules\Categorization\Public\Dto\RuleConditionDto;
use Modules\Categorization\Public\Enums\ActionType;
use Modules\Categorization\Public\Enums\ConditionOperator;
use Modules\Categorization\Public\Enums\ConditionValueType;
use Modules\Categorization\Public\Enums\NoteMode;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

trait MapsRuleRows
{
    // The four fields a condition may read, each under the name the reader
    // knows it by. The /rules list printed the raw column instead, so a Dutch
    // rule read `counterparty bevat "Netflix"` — half a sentence in English.
    /** @return array<string, string> */
    public static function fieldOptions(): array
    {
        return [
            'description' => Lang::get('categorization::rule_form.field_description'),
            'counterparty' => Lang::get('categorization::rule_form.field_counterparty'),
            'amount' => Lang::get('categorization::rule_form.field_amount'),
            'date' => Lang::get('categorization::rule_form.field_date'),
        ];
    }

    public static function fieldLabel(string $field): string
    {
        return self::fieldOptions()[$field] ?? $field;
    }

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
            'op' => ConditionOperator::Contains->value,
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
            'note_mode' => NoteMode::Set->value,
            'deduction_category_id' => null,
            'year_override_enabled' => false,
            'year_override' => null,
        ];
    }

    // Every read of the repeaters goes through these: a whole-property update
    // can replace a row with any shape at all, and the view indexes each row by
    // key. Rebuilding from the blank row is what makes a missing, extra or
    // wrong-typed key a normal row rather than a render fault.
    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<array{id: ?int, field: string, op: string, value: string, value2: ?string}>
     */
    private static function conditionRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = self::conditionRow($row);
        }

        return $normalized;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<array{id: ?int, type: string, category_id: ?int, counterparty_id: ?int, note_text: string, note_mode: string, deduction_category_id: ?int, year_override_enabled: bool, year_override: ?int}>
     */
    private static function actionRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = self::actionRow($row);
        }

        return $normalized;
    }

    /**
     * @return array{id: ?int, field: string, op: string, value: string, value2: ?string}
     */
    private static function conditionRow(mixed $row): array
    {
        $blank = self::blankCondition();
        if (! is_array($row)) {
            return $blank;
        }

        $field = self::textOr($row['field'] ?? null, $blank['field']);
        if (! array_key_exists($field, self::fieldOptions())) {
            $field = $blank['field'];
        }

        $validOps = array_keys(self::operatorOptionsFor($field));
        $op = self::textOr($row['op'] ?? null, $blank['op']);
        if (! in_array($op, $validOps, true)) {
            $op = $validOps[0] ?? $blank['op'];
        }

        $value2 = $row['value2'] ?? null;

        return [
            'id' => self::intIdOrNull($row['id'] ?? null),
            'field' => $field,
            'op' => $op,
            'value' => self::textOr($row['value'] ?? null, ''),
            'value2' => $value2 === null ? null : self::textOr($value2, ''),
        ];
    }

    /**
     * @return array{id: ?int, type: string, category_id: ?int, counterparty_id: ?int, note_text: string, note_mode: string, deduction_category_id: ?int, year_override_enabled: bool, year_override: ?int}
     */
    private static function actionRow(mixed $row): array
    {
        if (! is_array($row)) {
            return self::blankAction(ActionType::Category->value);
        }

        $type = ActionType::tryFrom(self::textOr($row['type'] ?? null, '')) ?? ActionType::Category;
        $noteMode = NoteMode::tryFrom(self::textOr($row['note_mode'] ?? null, '')) ?? NoteMode::Set;

        return [
            'id' => self::intIdOrNull($row['id'] ?? null),
            'type' => $type->value,
            'category_id' => self::intIdOrNull($row['category_id'] ?? null),
            'counterparty_id' => self::intIdOrNull($row['counterparty_id'] ?? null),
            'note_text' => self::textOr($row['note_text'] ?? null, ''),
            'note_mode' => $noteMode->value,
            'deduction_category_id' => self::intIdOrNull($row['deduction_category_id'] ?? null),
            'year_override_enabled' => ($row['year_override_enabled'] ?? null) === true,
            'year_override' => self::intIdOrNull($row['year_override'] ?? null),
        ];
    }

    // A number arrives from a <select> as an int and from a text input as a
    // string, so both are text here; anything else is a shape the form cannot
    // have produced and falls back rather than being cast.
    private static function textOr(mixed $value, string $fallback): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => $fallback,
        };
    }

    /**
     * @return array{id: ?int, field: string, op: string, value: string, value2: ?string}
     */
    private static function conditionFromDto(RuleConditionDto $dto): array
    {
        $field = $dto->valueType === ConditionValueType::Text->value ? $dto->field : $dto->valueType;

        // `merchant` and `counterparty` are one field under two names and the
        // select no longer offers both, so a rule still stored as `merchant`
        // would display as whichever option happens to come first.
        if ($field === 'merchant') {
            $field = 'counterparty';
        }

        if ($dto->valueType === ConditionValueType::Amount->value) {
            // The column holds signed minor units; the form shows them at the
            // reader's own scale, so the raw string must never reach the input
            // and a yen rule of 1250 is not written back as 12,50.
            $currency = BaseCurrency::value();
            $value = is_numeric($dto->value) ? MoneyInput::formatMinor((int) $dto->value, $currency) : $dto->value;
            $value2 = $dto->value2 !== null && is_numeric($dto->value2) ? MoneyInput::formatMinor((int) $dto->value2, $currency) : $dto->value2;
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

        if ($dto->type === ActionType::Category->value) {
            $row['category_id'] = self::intIdOrNull($payload['category_id'] ?? null);
        } elseif ($dto->type === ActionType::Counterparty->value) {
            $row['counterparty_id'] = self::intIdOrNull($payload['counterparty_id'] ?? null);
        } elseif ($dto->type === ActionType::Note->value) {
            $noteText = $payload['text'] ?? null;
            $row['note_text'] = is_string($noteText) ? $noteText : '';
            $noteMode = $payload['mode'] ?? null;
            $row['note_mode'] = is_string($noteMode) ? $noteMode : NoteMode::Set->value;
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
        if ($valueType !== ConditionValueType::Amount->value) {
            return false;
        }
        $currency = BaseCurrency::value();
        if ($value2 !== null && MoneyInput::tryToMinor($value2, $currency) === null) {
            return true;
        }

        return MoneyInput::tryToMinor($value, $currency) === null;
    }

    /**
     * @param  array{id: ?int, field: string, op: string, value: string, value2: ?string}  $condition
     * @return array{id: ?int, field: string, op: string, value_type: string, value: string, value2: ?string}
     */
    private static function conditionPayload(array $condition): array
    {
        $valueType = self::valueTypeFor($condition['field']);
        $dbField = $valueType === ConditionValueType::Text->value ? $condition['field'] : 'merchant';

        if ($valueType === ConditionValueType::Amount->value) {
            // conditionRowError() has already rejected an unparsable value, so
            // the `?? 0` fallback is unreachable rather than a silent zero.
            $currency = BaseCurrency::value();
            $value = (string) (MoneyInput::tryToMinor($condition['value'], $currency) ?? 0);
            $value2 = $condition['op'] === ConditionOperator::Between->value
                ? (string) (MoneyInput::tryToMinor($condition['value2'] ?? '', $currency) ?? 0)
                : null;
        } else {
            $value = trim($condition['value']);
            $value2 = $condition['op'] === ConditionOperator::Between->value ? trim($condition['value2'] ?? '') : null;
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
            ActionType::Category->value => ['category_id' => $action['category_id']],
            ActionType::Counterparty->value => ['counterparty_id' => $action['counterparty_id']],
            ActionType::Note->value => ['text' => trim($action['note_text']), 'mode' => $action['note_mode']],
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
