<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * Public action that inserts one categorization_rules row scoped to
 * the supplied user. The action is the sole permissible write path
 * from the UI / API into categorization_rules — the model itself is
 * fillable but every call site routes through this action so the
 * field/match whitelist + duplicate-translation logic stays in one
 * place.
 *
 * Validation contract:
 *   - `$field` MUST be one of `merchant` / `description` /
 *     `counterparty`. The DB layer enforces the same allow-list via
 *     paired BEFORE INSERT / BEFORE UPDATE triggers; the action's
 *     PHP-side check provides a clearer error message than a raw
 *     SQLite trigger abort.
 *   - `$match` MUST be one of `contains` / `equals` / `starts_with`.
 *     Same dual-layer enforcement.
 *   - `$value` MUST be non-empty (whitespace-trimmed). Empty values
 *     never produce a usable rule.
 *   - `$categoryId` MUST refer to a category visible to the caller
 *     (`categories.user_id IS NULL` for the seeded global tree OR
 *     `categories.user_id = $user->id` for owned categories). The
 *     `categories.id` FK alone is not enough — `categories` is multi-
 *     tenant and a tampered request could otherwise insert a rule
 *     pointing at a foreign user's category. Mirrors the visibility
 *     rule already enforced by `CategoryOptionsQuery::for($user)`.
 *     A miss throws `InvalidArgumentException` (the picker funnel
 *     keeps this practically unreachable from the UI; the guard
 *     hardens the direct-action / Livewire-DevTools path).
 *
 * Duplicate-rule mitigation: the (user_id, field, match, value)
 * UNIQUE constraint on the table rejects a second identical rule.
 * The action catches the resulting QueryException and translates it
 * into a Laravel ValidationException so the Livewire form modal can
 * render the locked copy under the offending field.
 */
final class CreateCategorizationRule
{
    private const VALID_FIELDS = ['merchant', 'description', 'counterparty'];

    private const VALID_MATCHES = ['contains', 'equals', 'starts_with'];

    private const DUPLICATE_MESSAGE = 'A rule with this field, match, and value already exists. Edit the existing rule instead.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function __invoke(User $user, string $field, string $match, string $value, int $categoryId): int
    {
        if (! in_array($field, self::VALID_FIELDS, true)) {
            throw new InvalidArgumentException(
                "CreateCategorizationRule: invalid field '{$field}'."
            );
        }
        if (! in_array($match, self::VALID_MATCHES, true)) {
            throw new InvalidArgumentException(
                "CreateCategorizationRule: invalid match '{$match}'."
            );
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new InvalidArgumentException(
                'CreateCategorizationRule: value must not be empty.'
            );
        }
        self::assertCategoryVisible($this->db, $categoryId, $user->id);

        $now = $this->clock->now()->toDateTimeString();

        try {
            return $this->db->connection()
                ->table('categorization_rules')
                ->insertGetId([
                    'user_id' => $user->id,
                    'field' => $field,
                    'match' => $match,
                    'value' => $trimmed,
                    'category_id' => $categoryId,
                    'hits_count' => 0,
                    'active' => true,
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
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

    /**
     * Verifies the supplied category is visible to the caller
     * (global default or owned). Throws InvalidArgumentException on
     * miss — mirrors the field/match validation shape.
     *
     * The duplicate guard lives in UpdateCategorizationRule as well;
     * keeping the helper static + parameterised matches the existing
     * `isUniqueViolation` shape (small private helper duplicated
     * across both sister actions rather than a separate trait or
     * service for two call sites).
     */
    private static function assertCategoryVisible(
        DatabaseManager $db,
        int $categoryId,
        int $userId,
    ): void {
        $exists = $db->connection()
            ->table('categories')
            ->where('id', $categoryId)
            ->where(static function ($query) use ($userId): void {
                $query->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->exists();
        if (! $exists) {
            throw new InvalidArgumentException(
                "CreateCategorizationRule: category {$categoryId} is not visible to user {$userId}."
            );
        }
    }
}
