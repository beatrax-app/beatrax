<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Actions\Concerns;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Categorization\Public\Enums\ActionType;
use Modules\Categorization\Public\Enums\ConditionOperator;
use Modules\Categorization\Public\Enums\ConditionValueType;
use Modules\Categorization\Public\Enums\NoteMode;
use Modules\Categorization\Public\Enums\RuleCombinator;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

trait NormalisesRuleInput
{
    // The one vocabulary that is not an enum: `field` names a transaction
    // attribute, not a closed rule-domain concept, so it stays a plain list.
    private const VALID_CONDITION_FIELDS = ['merchant', 'description', 'counterparty'];

    private const AMOUNT_VALUE_PATTERN = '/^-?\d+$/';

    /**
     * @return array{conditions: list<array{id: ?int, field: string, op: string, value_type: string, value: string, value2: ?string}>, actions: list<array{id: ?int, position: int, type: string, payload: array<string, mixed>}>}
     */
    private static function normalizeInput(DatabaseManager $db, RuleInput $input, User $user): array
    {
        if (RuleCombinator::tryFrom($input->combinator) === null) {
            throw new InvalidArgumentException(
                "Categorization rule: invalid combinator '{$input->combinator}'."
            );
        }

        if ($input->conditions === []) {
            throw ValidationException::withMessages([
                'conditions' => Lang::get('categorization::rule_form.error_add_condition'),
            ]);
        }
        if ($input->actions === []) {
            throw ValidationException::withMessages([
                'actions' => Lang::get('categorization::rule_form.error_add_action'),
            ]);
        }

        $conditions = [];
        foreach ($input->conditions as $condition) {
            $conditions[] = self::normalizeCondition($condition);
        }

        $actions = [];
        foreach ($input->actions as $index => $action) {
            $actions[] = self::normalizeAction($db, $action, $index, $user->id);
        }

        return ['conditions' => $conditions, 'actions' => $actions];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array{id: ?int, field: string, op: string, value_type: string, value: string, value2: ?string}
     */
    private static function normalizeCondition(array $condition): array
    {
        $valueTypeRaw = self::stringOrDefault($condition, 'value_type', '');
        $valueType = ConditionValueType::tryFrom($valueTypeRaw);
        if ($valueType === null) {
            throw new InvalidArgumentException(
                "Categorization rule: invalid value_type '{$valueTypeRaw}'."
            );
        }

        $opRaw = self::stringOrDefault($condition, 'op', '');
        $op = ConditionOperator::tryFrom($opRaw);
        if ($op === null || ! in_array($op, $valueType->operators(), true)) {
            throw new InvalidArgumentException(
                "Categorization rule: op '{$opRaw}' is not valid for value_type '{$valueTypeRaw}'."
            );
        }

        $value = trim(self::stringOrDefault($condition, 'value', ''));
        if ($value === '') {
            throw new InvalidArgumentException(
                'Categorization rule: condition value must not be empty.'
            );
        }

        $value2 = self::normalizedValue2($condition);
        if ($op === ConditionOperator::Between && $value2 === null) {
            throw new InvalidArgumentException(
                "Categorization rule: op 'between' requires a non-null value2."
            );
        }

        if ($valueType === ConditionValueType::Amount) {
            self::assertAmountMinorUnits($value, $value2);
        }

        $field = self::stringOrDefault($condition, 'field', 'merchant');
        if (! in_array($field, self::VALID_CONDITION_FIELDS, true)) {
            throw new InvalidArgumentException(
                "Categorization rule: invalid field '{$field}'."
            );
        }

        return [
            'id' => self::intOrNull($condition, 'id'),
            'field' => $field,
            'op' => $op->value,
            'value_type' => $valueType->value,
            'value' => $value,
            'value2' => $value2,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function stringOrDefault(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function intOrNull(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    // A blank value2 collapses to null; a `between` op missing it is rejected
    // upstream rather than here.
    /**
     * @param  array<string, mixed>  $condition
     */
    private static function normalizedValue2(array $condition): ?string
    {
        $raw = $condition['value2'] ?? null;
        if (! is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }

    // The Euro-decimal -> minor-unit scaling is the caller's job (see
    // MapsRuleRows::conditionPayload), so a raw decimal Euro string is rejected
    // here rather than silently truncated at match time.
    private static function assertAmountMinorUnits(string $value, ?string $value2): void
    {
        if (preg_match(self::AMOUNT_VALUE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                "Categorization rule: amount condition value '{$value}' must be an integer minor-unit string."
            );
        }
        if ($value2 !== null && preg_match(self::AMOUNT_VALUE_PATTERN, $value2) !== 1) {
            throw new InvalidArgumentException(
                "Categorization rule: amount condition value2 '{$value2}' must be an integer minor-unit string."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{id: ?int, position: int, type: string, payload: array<string, mixed>}
     */
    private static function normalizeAction(DatabaseManager $db, array $action, int $index, int $userId): array
    {
        $typeRaw = self::stringOrDefault($action, 'type', '');
        $type = ActionType::tryFrom($typeRaw);
        if ($type === null) {
            throw new InvalidArgumentException(
                "Categorization rule: invalid action type '{$typeRaw}'."
            );
        }

        $rawPayload = self::toStringKeyedArray($action['payload'] ?? null);

        // TaxTag is the only case left once Category/Counterparty/Note are
        // handled — written as `default` to avoid an always-true match arm.
        $payload = match ($type) {
            ActionType::Category => self::normalizeCategoryPayload($db, $rawPayload, $userId),
            ActionType::Counterparty => self::normalizeCounterpartyPayload($db, $rawPayload, $userId),
            ActionType::Note => self::normalizeNotePayload($rawPayload),
            default => self::normalizeTaxTagPayload($db, $rawPayload, $userId),
        };

        $position = isset($action['position']) && is_int($action['position']) ? $action['position'] : $index;

        return [
            'id' => self::intOrNull($action, 'id'),
            'position' => $position,
            'type' => $type->value,
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function toStringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{category_id: int}
     */
    private static function normalizeCategoryPayload(DatabaseManager $db, array $payload, int $userId): array
    {
        $categoryId = isset($payload['category_id']) && is_numeric($payload['category_id']) ? (int) $payload['category_id'] : 0;
        if ($categoryId <= 0) {
            throw new InvalidArgumentException('Categorization rule: category action requires a category_id.');
        }
        self::assertReferentVisible($db, 'categories', $categoryId, $userId, true);

        return ['category_id' => $categoryId];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{counterparty_id: int}
     */
    private static function normalizeCounterpartyPayload(DatabaseManager $db, array $payload, int $userId): array
    {
        $counterpartyId = isset($payload['counterparty_id']) && is_numeric($payload['counterparty_id']) ? (int) $payload['counterparty_id'] : 0;
        if ($counterpartyId <= 0) {
            throw new InvalidArgumentException('Categorization rule: counterparty action requires a counterparty_id.');
        }
        self::assertReferentVisible($db, 'counterparties', $counterpartyId, $userId, false);

        return ['counterparty_id' => $counterpartyId];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{text: string, mode: string}
     */
    private static function normalizeNotePayload(array $payload): array
    {
        $text = isset($payload['text']) && is_string($payload['text']) ? trim($payload['text']) : '';
        if ($text === '') {
            throw new InvalidArgumentException('Categorization rule: note action requires non-empty text.');
        }
        $mode = isset($payload['mode']) && is_string($payload['mode'])
            ? NoteMode::tryFrom($payload['mode']) ?? NoteMode::Set
            : NoteMode::Set;

        return ['text' => $text, 'mode' => $mode->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{deduction_category_id: int, year?: int}
     */
    private static function normalizeTaxTagPayload(DatabaseManager $db, array $payload, int $userId): array
    {
        $deductionCategoryId = isset($payload['deduction_category_id']) && is_numeric($payload['deduction_category_id'])
            ? (int) $payload['deduction_category_id']
            : 0;
        if ($deductionCategoryId <= 0) {
            throw new InvalidArgumentException('Categorization rule: tax_tag action requires a deduction_category_id.');
        }
        self::assertReferentVisible($db, 'tax_deduction_categories', $deductionCategoryId, $userId, false);

        $result = ['deduction_category_id' => $deductionCategoryId];
        if (isset($payload['year']) && is_numeric($payload['year'])) {
            $result['year'] = (int) $payload['year'];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function encodePayload(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Categorization rule: action payload could not be encoded.', 0, $e);
        }
    }

    // A method, not a const, so the translation is read at call time.
    private static function duplicateMessage(): string
    {
        return Lang::get('categorization::rule_form.error_duplicate');
    }

    // Categories carry global (null-user_id) rows a user may reference;
    // counterparties and deduction categories are user-scoped only, so
    // $allowGlobal is where the IDOR line falls.
    private static function assertReferentVisible(
        DatabaseManager $db,
        string $table,
        int $id,
        int $userId,
        bool $allowGlobal,
    ): void {
        $exists = $db->connection()
            ->table($table)
            ->where('id', $id)
            ->where(static function (QueryBuilder $query) use ($userId, $allowGlobal): void {
                $query->where('user_id', $userId);
                if ($allowGlobal) {
                    $query->orWhereNull('user_id');
                }
            })
            ->exists();
        if (! $exists) {
            throw new InvalidArgumentException(
                "Categorization rule: referent {$id} in {$table} is not visible to user {$userId}."
            );
        }
    }
}
