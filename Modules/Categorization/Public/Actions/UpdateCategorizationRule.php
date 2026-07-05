<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Actions;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public action that updates one `categorization_rules` parent row
 * plus its `rule_conditions` / `rule_actions` children (D-01/D-02/
 * D-03).
 *
 * Cross-user safety: the parent row is looked up via `where('id',
 * $ruleId)->where('user_id', $user->id)`. A foreign-user id throws
 * `NotFoundHttpException` (404, not 403, so the existence of other
 * users' rules is not leaked).
 *
 * Child-row reconciliation is a targeted, PK-PRESERVING
 * UPDATE/DELETE/INSERT diff keyed by the caller-supplied `id` (present
 * only for a row that already exists) — never a delete-all+reinsert —
 * mirroring `SaveTransactionSplit`'s leg-diffing convention so a
 * future per-(table, pk, field) LWW sync merge never diverges. A
 * caller-supplied `id` that does not belong to THIS rule (a forged or
 * cross-rule id) is silently treated as absent, i.e. inserted as a new
 * row, rather than ever updating a row scoped to a different rule.
 *
 * Validation contract mirrors `CreateCategorizationRule` exactly (op x
 * value_type matrix, payload-id ownership, at-least-one-condition/
 * -action) — see that class's docblock for the full rationale.
 *
 * Duplicate-rule mitigation mirrors `CreateCategorizationRule`: a
 * `QueryException` is translated to a `ValidationException` rather
 * than surfacing a raw SQL error, even though the redesigned schema
 * carries no UNIQUE constraint today.
 */
final class UpdateCategorizationRule
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

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * `$conditions`/`$actions` are untrusted, caller-supplied arrays
     * (a Livewire form's repeater payload, or a direct-action /
     * DevTools call) — every element is validated field-by-field
     * before use, never assumed to already conform to a shape. An
     * element's `id` key (present only for a row that already exists)
     * is optional.
     *
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<array<string, mixed>>  $actions
     */
    public function __invoke(
        User $user,
        int $ruleId,
        int $priority,
        string $combinator,
        bool $active,
        ?string $notes,
        array $conditions,
        array $actions,
    ): int {
        $connection = $this->db->connection();

        $row = $connection
            ->table('categorization_rules')
            ->where('id', $ruleId)
            ->where('user_id', $user->id)
            ->first();

        if ($row === null) {
            throw new NotFoundHttpException('Rule not found.');
        }

        if (! in_array($combinator, self::VALID_COMBINATORS, true)) {
            throw new InvalidArgumentException(
                "UpdateCategorizationRule: invalid combinator '{$combinator}'."
            );
        }

        if ($conditions === []) {
            throw ValidationException::withMessages([
                'conditions' => 'Add at least one condition.',
            ]);
        }
        if ($actions === []) {
            throw ValidationException::withMessages([
                'actions' => 'Add at least one action.',
            ]);
        }

        $normalizedConditions = [];
        foreach ($conditions as $condition) {
            $normalizedConditions[] = self::normalizeCondition($condition);
        }

        $normalizedActions = [];
        foreach ($actions as $index => $action) {
            $normalizedActions[] = self::normalizeAction($this->db, $action, $index, $user->id);
        }

        $now = $this->clock->now()->toDateTimeString();

        try {
            return $connection->transaction(static function () use ($connection, $ruleId, $user, $priority, $combinator, $active, $notes, $normalizedConditions, $normalizedActions, $now): int {
                $connection
                    ->table('categorization_rules')
                    ->where('id', $ruleId)
                    ->where('user_id', $user->id)
                    ->update([
                        'priority' => $priority,
                        'combinator' => $combinator,
                        'active' => $active,
                        'notes' => $notes,
                        'updated_at' => $now,
                    ]);

                self::diffConditions($connection, $ruleId, $normalizedConditions, $now);
                self::diffActions($connection, $ruleId, $normalizedActions, $now);

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
     * @param  list<array{id: ?int, field: string, op: string, value_type: string, value: string, value2: ?string}>  $conditions
     */
    private static function diffConditions(ConnectionInterface $connection, int $ruleId, array $conditions, string $now): void
    {
        /** @var list<int> $existingIds */
        $existingIds = $connection->table('rule_conditions')
            ->where('rule_id', $ruleId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        $incomingIds = [];
        foreach ($conditions as $condition) {
            $id = $condition['id'];
            if ($id !== null && in_array($id, $existingIds, true)) {
                $incomingIds[] = $id;
            }
        }

        foreach ($conditions as $condition) {
            $id = $condition['id'];
            $fields = [
                'field' => $condition['field'],
                'op' => $condition['op'],
                'value_type' => $condition['value_type'],
                'value' => $condition['value'],
                'value2' => $condition['value2'],
            ];

            if ($id !== null && in_array($id, $existingIds, true)) {
                $connection->table('rule_conditions')
                    ->where('id', $id)
                    ->where('rule_id', $ruleId)
                    ->update($fields + ['updated_at' => $now]);
            } else {
                $connection->table('rule_conditions')->insert($fields + [
                    'rule_id' => $ruleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $removedIds = array_diff($existingIds, $incomingIds);
        if ($removedIds !== []) {
            $connection->table('rule_conditions')
                ->where('rule_id', $ruleId)
                ->whereIn('id', $removedIds)
                ->delete();
        }
    }

    /**
     * @param  list<array{id: ?int, position: int, type: string, payload: array<string, mixed>}>  $actions
     */
    private static function diffActions(ConnectionInterface $connection, int $ruleId, array $actions, string $now): void
    {
        /** @var list<int> $existingIds */
        $existingIds = $connection->table('rule_actions')
            ->where('rule_id', $ruleId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        $incomingIds = [];
        foreach ($actions as $action) {
            $id = $action['id'];
            if ($id !== null && in_array($id, $existingIds, true)) {
                $incomingIds[] = $id;
            }
        }

        foreach ($actions as $action) {
            $id = $action['id'];
            $fields = [
                'position' => $action['position'],
                'type' => $action['type'],
                'payload' => self::encodePayload($action['payload']),
            ];

            if ($id !== null && in_array($id, $existingIds, true)) {
                $connection->table('rule_actions')
                    ->where('id', $id)
                    ->where('rule_id', $ruleId)
                    ->update($fields + ['updated_at' => $now]);
            } else {
                $connection->table('rule_actions')->insert($fields + [
                    'rule_id' => $ruleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $removedIds = array_diff($existingIds, $incomingIds);
        if ($removedIds !== []) {
            $connection->table('rule_actions')
                ->where('rule_id', $ruleId)
                ->whereIn('id', $removedIds)
                ->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array{id: ?int, field: string, op: string, value_type: string, value: string, value2: ?string}
     */
    private static function normalizeCondition(array $condition): array
    {
        $valueType = isset($condition['value_type']) && is_string($condition['value_type']) ? $condition['value_type'] : '';
        if (! array_key_exists($valueType, self::OP_VALUE_TYPE_MATRIX)) {
            throw new InvalidArgumentException(
                "UpdateCategorizationRule: invalid value_type '{$valueType}'."
            );
        }

        $op = isset($condition['op']) && is_string($condition['op']) ? $condition['op'] : '';
        if (! in_array($op, self::OP_VALUE_TYPE_MATRIX[$valueType], true)) {
            throw new InvalidArgumentException(
                "UpdateCategorizationRule: op '{$op}' is not valid for value_type '{$valueType}'."
            );
        }

        $value = isset($condition['value']) && is_string($condition['value']) ? trim($condition['value']) : '';
        if ($value === '') {
            throw new InvalidArgumentException(
                'UpdateCategorizationRule: condition value must not be empty.'
            );
        }

        $rawValue2 = $condition['value2'] ?? null;
        $value2 = is_string($rawValue2) ? trim($rawValue2) : null;
        $value2 = $value2 === '' ? null : $value2;

        if ($op === 'between' && $value2 === null) {
            throw new InvalidArgumentException(
                "UpdateCategorizationRule: op 'between' requires a non-null value2."
            );
        }

        $field = isset($condition['field']) && is_string($condition['field']) ? $condition['field'] : 'merchant';
        if (! in_array($field, self::VALID_CONDITION_FIELDS, true)) {
            throw new InvalidArgumentException(
                "UpdateCategorizationRule: invalid field '{$field}'."
            );
        }

        $id = isset($condition['id']) && is_numeric($condition['id']) ? (int) $condition['id'] : null;

        return [
            'id' => $id,
            'field' => $field,
            'op' => $op,
            'value_type' => $valueType,
            'value' => $value,
            'value2' => $value2,
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{id: ?int, position: int, type: string, payload: array<string, mixed>}
     */
    private static function normalizeAction(DatabaseManager $db, array $action, int $index, int $userId): array
    {
        $type = isset($action['type']) && is_string($action['type']) ? $action['type'] : '';
        if (! in_array($type, self::VALID_ACTION_TYPES, true)) {
            throw new InvalidArgumentException(
                "UpdateCategorizationRule: invalid action type '{$type}'."
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
        $id = isset($action['id']) && is_numeric($action['id']) ? (int) $action['id'] : null;

        return [
            'id' => $id,
            'position' => $position,
            'type' => $type,
            'payload' => $payload,
        ];
    }

    /**
     * Coerces an arbitrary (untrusted) value into a string-keyed array,
     * discarding it entirely (empty array) unless it already is one —
     * never partially trusts a mixed-keyed array.
     *
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
            throw new InvalidArgumentException('UpdateCategorizationRule: category action requires a category_id.');
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
            throw new InvalidArgumentException('UpdateCategorizationRule: counterparty action requires a counterparty_id.');
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
            throw new InvalidArgumentException('UpdateCategorizationRule: note action requires non-empty text.');
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
            throw new InvalidArgumentException('UpdateCategorizationRule: tax_tag action requires a deduction_category_id.');
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
            throw new InvalidArgumentException('UpdateCategorizationRule: action payload could not be encoded.', 0, $e);
        }
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();
        if ($sqlState === '23000') {
            return true;
        }
        $message = $e->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'duplicate key value');
    }

    /**
     * Verifies the supplied category is visible to the caller
     * (global default or owned). Throws InvalidArgumentException on
     * miss — mirrors the sibling guard on `CreateCategorizationRule`.
     */
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
                "UpdateCategorizationRule: category {$categoryId} is not visible to user {$userId}."
            );
        }
    }

    /**
     * Verifies the supplied counterparty belongs to the caller —
     * mirrors the sibling guard on `CreateCategorizationRule`.
     */
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
                "UpdateCategorizationRule: counterparty {$counterpartyId} is not visible to user {$userId}."
            );
        }
    }

    /**
     * Verifies the supplied deduction category belongs to the caller —
     * mirrors the sibling guard on `CreateCategorizationRule`.
     */
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
                "UpdateCategorizationRule: deduction category {$deductionCategoryId} is not visible to user {$userId}."
            );
        }
    }
}
