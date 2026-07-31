<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire\Concerns;

use Modules\Categorization\Public\Dto\RuleActionDto;
use Modules\Categorization\Public\Dto\RuleConditionDto;

// The pure translation layer between the form's UI rows and the backend's
// (field, value_type)/payload shapes: field-to-type maps, blank-row
// factories, DTO<->row round-trips, and Dutch-Euro amount parsing. Every
// method is static, so it sits beside RuleFormModal rather than inside it.
/**
 * @link ../../../../../../.docs/features/categorization/architecture.md
 */
trait MapsRuleRows
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

    /** @return array<string, string> */
    public static function operatorOptionsFor(string $field): array
    {
        return self::OP_OPTIONS[self::valueTypeFor($field)];
    }

    public static function valueTypeFor(string $field): string
    {
        return self::CONDITION_FIELDS[$field] ?? 'string';
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
            // The DB stores signed integer minor units; the form input
            // displays the project's Dutch-decimal Euro convention, so
            // round-trip through formatAmount() rather than showing the
            // raw minor-unit string back to the user.
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

    // An amount condition's bounds must each parse; a non-amount value type
    // never fails here. value2 is only present for a `between` op.
    private static function hasInvalidAmount(string $valueType, string $value, ?string $value2): bool
    {
        if ($valueType !== 'amount') {
            return false;
        }
        if ($value2 !== null && self::parseAmount($value2) === null) {
            return true;
        }

        return self::parseAmount($value) === null;
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
            // into signed integer minor units before persistence.
            // conditionRowError() has already rejected an unparsable
            // value by this point, so the `?? 0` fallback is unreachable.
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

    // Signed (allows a leading '-') because an amount condition compares
    // against settledAmountMinor, a signed BIGINT. Accepts plain
    // ("12.50"), Dutch grouped ("1.234,56"), and comma-decimal ("12,50")
    // forms; the rightmost of '.' or ',' is the decimal separator.
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

    // e.g. -5000 -> "-50,00", so an amount condition loaded via
    // conditionFromDto() round-trips through parseAmount() unchanged if
    // the user submits it untouched.
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
}
