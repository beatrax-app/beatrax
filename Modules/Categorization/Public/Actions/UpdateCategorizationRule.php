<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public action that updates one categorization_rules row.
 *
 * Cross-user safety: the row is looked up via
 * `where('id', $ruleId)->where('user_id', $user->id)`. A foreign-user
 * id throws NotFoundHttpException (404, not 403, so the existence of
 * other users' rules is not leaked).
 *
 * Allowed update keys: `field`, `match`, `value`, `category_id`,
 * `active`, `notes`. Any other key is silently dropped — the action
 * never propagates unexpected payload keys to the SQL builder. The
 * same field / match allow-lists as CreateCategorizationRule apply.
 *
 * Category-ownership guard: when `category_id` is part of the update
 * payload, the supplied id MUST refer to a category visible to the
 * caller (`categories.user_id IS NULL` for the seeded global tree
 * OR `categories.user_id = $user->id` for owned categories). A miss
 * throws `InvalidArgumentException`. Mirrors the visibility rule
 * enforced by `CategoryOptionsQuery::for($user)` and the equivalent
 * guard in `CreateCategorizationRule`.
 *
 * Duplicate-rule mitigation mirrors CreateCategorizationRule: a
 * UNIQUE-violation QueryException is translated to a Laravel
 * ValidationException with a calm copy under the `value` field so
 * the form modal renders the error inline.
 */
final class UpdateCategorizationRule
{
    private const VALID_FIELDS = ['merchant', 'description', 'counterparty'];

    private const VALID_MATCHES = ['contains', 'equals', 'starts_with'];

    private const ALLOWED_KEYS = ['field', 'match', 'value', 'category_id', 'active', 'notes'];

    private const DUPLICATE_MESSAGE = 'A rule with this field, match, and value already exists. Edit the existing rule instead.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $updates
     */
    public function __invoke(User $user, int $ruleId, array $updates): int
    {
        $connection = $this->db->connection();

        $row = $connection
            ->table('categorization_rules')
            ->where('id', $ruleId)
            ->where('user_id', $user->id)
            ->first();

        if ($row === null) {
            throw new NotFoundHttpException('Rule not found.');
        }

        $payload = [];
        foreach ($updates as $key => $value) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }
            $payload[$key] = $value;
        }

        if (isset($payload['field'])) {
            $fieldValue = is_string($payload['field']) ? $payload['field'] : '';
            if (! in_array($fieldValue, self::VALID_FIELDS, true)) {
                throw new InvalidArgumentException(
                    "UpdateCategorizationRule: invalid field '{$fieldValue}'."
                );
            }
        }
        if (isset($payload['match'])) {
            $matchValue = is_string($payload['match']) ? $payload['match'] : '';
            if (! in_array($matchValue, self::VALID_MATCHES, true)) {
                throw new InvalidArgumentException(
                    "UpdateCategorizationRule: invalid match '{$matchValue}'."
                );
            }
        }
        if (isset($payload['value'])) {
            $rawValue = is_string($payload['value']) ? $payload['value'] : '';
            $trimmed = trim($rawValue);
            if ($trimmed === '') {
                throw new InvalidArgumentException(
                    'UpdateCategorizationRule: value must not be empty.'
                );
            }
            $payload['value'] = $trimmed;
        }
        if (isset($payload['category_id'])) {
            $categoryId = is_numeric($payload['category_id'])
                ? (int) $payload['category_id']
                : 0;
            self::assertCategoryVisible($this->db, $categoryId, $user->id);
            $payload['category_id'] = $categoryId;
        }

        if ($payload === []) {
            // Nothing to write — preserve idempotency by returning 0.
            return 0;
        }

        $payload['updated_at'] = $this->clock->now()->toDateTimeString();

        try {
            return $connection->transaction(function () use ($connection, $ruleId, $user, $payload): int {
                return $connection
                    ->table('categorization_rules')
                    ->where('id', $ruleId)
                    ->where('user_id', $user->id)
                    ->update($payload);
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
     * miss — mirrors the field/match validation shape and the
     * sibling guard on `CreateCategorizationRule`.
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
}
