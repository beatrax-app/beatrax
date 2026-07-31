<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * @link ../../../../.docs/features/categorization/architecture.md
 */
final class CreateCategorizationRule
{
    private const VALID_COMBINATORS = ['all', 'any'];

    private const VALID_CONDITION_FIELDS = ['merchant', 'description', 'counterparty'];

    private const VALID_ACTION_TYPES = ['category', 'counterparty', 'note', 'tax_tag'];

    private const VALID_NOTE_MODES = ['set', 'append'];

    /** @var array<string, list<string>> */
    private const OP_VALUE_TYPE_MATRIX = [
        'string' => ['contains', 'equals', 'starts_with'],
        'amount' => ['>', '<', 'between', 'equals'],
        'date' => ['before', 'after', 'between'],
    ];

    private const DUPLICATE_MESSAGE = 'A rule with this field, match, and value already exists. Edit the existing rule instead.';

    // Rejects a caller that skips the Euro-decimal -> minor-unit scaling
    // RuleFormModal::conditionPayload() normally performs, instead of
    // silently truncating a raw decimal Euro string at match time.
    private const AMOUNT_VALUE_PATTERN = '/^-?\d+$/';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    // $input->conditions/$input->actions are untrusted, caller-supplied
    // arrays; every element is validated field-by-field before use, never
    // assumed to already conform to a shape.
    public function __invoke(
        User $user,
        RuleInput $input,
    ): int {
        if (! in_array($input->combinator, self::VALID_COMBINATORS, true)) {
            throw new InvalidArgumentException(
                "CreateCategorizationRule: invalid combinator '{$input->combinator}'."
            );
        }

        if ($input->conditions === []) {
            throw ValidationException::withMessages([
                'conditions' => 'Add at least one condition.',
            ]);
        }
        if ($input->actions === []) {
            throw ValidationException::withMessages([
                'actions' => 'Add at least one action.',
            ]);
        }

        $normalizedConditions = [];
        foreach ($input->conditions as $condition) {
            $normalizedConditions[] = self::normalizeCondition($condition);
        }

        $normalizedActions = [];
        foreach ($input->actions as $index => $action) {
            $normalizedActions[] = self::normalizeAction($this->db, $action, $index, $user->id);
        }

        $now = $this->clock->now()->toDateTimeString();
        $db = $this->db;

        try {
            return $db->connection()->transaction(static function () use ($db, $normalizedConditions, $normalizedActions, $user, $input, $now): int {
                $connection = $db->connection();

                $ruleId = $connection->table('categorization_rules')->insertGetId([
                    'user_id' => $user->id,
                    'priority' => $input->priority,
                    'combinator' => $input->combinator,
                    'active' => $input->active,
                    'notes' => $input->notes,
                    'hits_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($normalizedConditions as $condition) {
                    $connection->table('rule_conditions')->insert([
                        'rule_id' => $ruleId,
                        'field' => $condition['field'],
                        'op' => $condition['op'],
                        'value_type' => $condition['value_type'],
                        'value' => $condition['value'],
                        'value2' => $condition['value2'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                foreach ($normalizedActions as $action) {
                    $connection->table('rule_actions')->insert([
                        'rule_id' => $ruleId,
                        'position' => $action['position'],
                        'type' => $action['type'],
                        'payload' => self::encodePayload($action['payload']),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return $ruleId;
            });
        } catch (QueryException $e) {
            if (self::isUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'value' => self::DUPLICATE_MESSAGE,
                ]);
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array{field: string, op: string, value_type: string, value: string, value2: ?string}
     */
    private static function normalizeCondition(array $condition): array
    {
        $valueType = self::stringOrDefault($condition, 'value_type', '');
        if (! array_key_exists($valueType, self::OP_VALUE_TYPE_MATRIX)) {
            throw new InvalidArgumentException(
                "CreateCategorizationRule: invalid value_type '{$valueType}'."
            );
        }

        $op = self::stringOrDefault($condition, 'op', '');
        if (! in_array($op, self::OP_VALUE_TYPE_MATRIX[$valueType], true)) {
            throw new InvalidArgumentException(
                "CreateCategorizationRule: op '{$op}' is not valid for value_type '{$valueType}'."
            );
        }

        $value = trim(self::stringOrDefault($condition, 'value', ''));
        if ($value === '') {
            throw new InvalidArgumentException(
                'CreateCategorizationRule: condition value must not be empty.'
            );
        }

        $value2 = self::normalizedValue2($condition);
        if ($op === 'between' && $value2 === null) {
            throw new InvalidArgumentException(
                "CreateCategorizationRule: op 'between' requires a non-null value2."
            );
        }

        if ($valueType === 'amount') {
            self::assertAmountMinorUnits($value, $value2);
        }

        $field = self::stringOrDefault($condition, 'field', 'merchant');
        if (! in_array($field, self::VALID_CONDITION_FIELDS, true)) {
            throw new InvalidArgumentException(
                "CreateCategorizationRule: invalid field '{$field}'."
            );
        }

        return [
            'field' => $field,
            'op' => $op,
            'value_type' => $valueType,
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

    // A blank value2 collapses to null so an absent upper bound and an empty
    // one are the same thing — a `between` op without it is rejected upstream.
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

    // Both bounds must already be integer minor-unit strings — the caller is
    // responsible for the Euro-decimal -> minor-unit scaling, so a raw decimal
    // Euro string is rejected here rather than silently truncated at match time.
    private static function assertAmountMinorUnits(string $value, ?string $value2): void
    {
        if (preg_match(self::AMOUNT_VALUE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                "CreateCategorizationRule: amount condition value '{$value}' must be an integer minor-unit string."
            );
        }
        if ($value2 !== null && preg_match(self::AMOUNT_VALUE_PATTERN, $value2) !== 1) {
            throw new InvalidArgumentException(
                "CreateCategorizationRule: amount condition value2 '{$value2}' must be an integer minor-unit string."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{position: int, type: string, payload: array<string, mixed>}
     */
    private static function normalizeAction(DatabaseManager $db, array $action, int $index, int $userId): array
    {
        $type = isset($action['type']) && is_string($action['type']) ? $action['type'] : '';
        if (! in_array($type, self::VALID_ACTION_TYPES, true)) {
            throw new InvalidArgumentException(
                "CreateCategorizationRule: invalid action type '{$type}'."
            );
        }

        $rawPayload = self::toStringKeyedArray($action['payload'] ?? null);

        // 'tax_tag' is the only action type left once category/
        // counterparty/note are excluded above (VALID_ACTION_TYPES has
        // exactly 4 members) — expressed as `default` rather than an
        // explicit 'tax_tag' arm to avoid an always-true match branch.
        $payload = match ($type) {
            'category' => self::normalizeCategoryPayload($db, $rawPayload, $userId),
            'counterparty' => self::normalizeCounterpartyPayload($db, $rawPayload, $userId),
            'note' => self::normalizeNotePayload($rawPayload),
            default => self::normalizeTaxTagPayload($db, $rawPayload, $userId),
        };

        $position = isset($action['position']) && is_int($action['position']) ? $action['position'] : $index;

        return [
            'position' => $position,
            'type' => $type,
            'payload' => $payload,
        ];
    }

    // Discards the value entirely (empty array) unless it already is a
    // string-keyed array — never partially trusts a mixed-keyed array.
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
            throw new InvalidArgumentException('CreateCategorizationRule: category action requires a category_id.');
        }
        self::assertCategoryVisible($db, $categoryId, $userId);

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
            throw new InvalidArgumentException('CreateCategorizationRule: counterparty action requires a counterparty_id.');
        }
        self::assertCounterpartyVisible($db, $counterpartyId, $userId);

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
            throw new InvalidArgumentException('CreateCategorizationRule: note action requires non-empty text.');
        }
        $mode = isset($payload['mode']) && is_string($payload['mode']) && in_array($payload['mode'], self::VALID_NOTE_MODES, true)
            ? $payload['mode']
            : 'set';

        return ['text' => $text, 'mode' => $mode];
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
            throw new InvalidArgumentException('CreateCategorizationRule: tax_tag action requires a deduction_category_id.');
        }
        self::assertDeductionCategoryVisible($db, $deductionCategoryId, $userId);

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
            throw new InvalidArgumentException('CreateCategorizationRule: action payload could not be encoded.', 0, $e);
        }
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        // SQLite reports UNIQUE violations with SQLSTATE 23000 and a
        // message containing "UNIQUE constraint failed". MySQL +
        // Postgres also surface 23000 for unique-constraint violations.
        $sqlState = (string) $e->getCode();
        if ($sqlState === '23000') {
            return true;
        }
        $message = $e->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'duplicate key value');
    }

    // The IDOR seam for a `category`-type action payload's embedded
    // category_id: global default or user-owned only.
    private static function assertCategoryVisible(
        DatabaseManager $db,
        int $categoryId,
        int $userId,
    ): void {
        $exists = $db->connection()
            ->table('categories')
            ->where('id', $categoryId)
            ->where(static function (QueryBuilder $query) use ($userId): void {
                $query->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->exists();
        if (! $exists) {
            throw new InvalidArgumentException(
                "CreateCategorizationRule: category {$categoryId} is not visible to user {$userId}."
            );
        }
    }

    // The IDOR seam for a `counterparty`-type action payload's embedded
    // counterparty_id. Counterparties carry no global/null-user_id rows,
    // so this is a plain user_id equality scope.
    private static function assertCounterpartyVisible(
        DatabaseManager $db,
        int $counterpartyId,
        int $userId,
    ): void {
        $exists = $db->connection()
            ->table('counterparties')
            ->where('id', $counterpartyId)
            ->where('user_id', $userId)
            ->exists();
        if (! $exists) {
            throw new InvalidArgumentException(
                "CreateCategorizationRule: counterparty {$counterpartyId} is not visible to user {$userId}."
            );
        }
    }

    // The IDOR seam for a `tax_tag`-type action payload's embedded
    // deduction_category_id (plain user_id equality, no global fallback).
    private static function assertDeductionCategoryVisible(
        DatabaseManager $db,
        int $deductionCategoryId,
        int $userId,
    ): void {
        $exists = $db->connection()
            ->table('tax_deduction_categories')
            ->where('id', $deductionCategoryId)
            ->where('user_id', $userId)
            ->exists();
        if (! $exists) {
            throw new InvalidArgumentException(
                "CreateCategorizationRule: deduction category {$deductionCategoryId} is not visible to user {$userId}."
            );
        }
    }
}
