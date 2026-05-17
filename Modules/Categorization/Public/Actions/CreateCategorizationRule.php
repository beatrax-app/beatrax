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
}
