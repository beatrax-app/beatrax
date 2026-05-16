<?php

declare(strict_types=1);

namespace Modules\Chains\Internal;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Public\Dto\StatementSettlement;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use RuntimeException;

/**
 * The single legal mutator of `card_statements.state` and
 * `card_statements.open_balance_minor`. A BoundaryArchTest invariant
 * blocks any other write path under `Modules/Chains/`.
 *
 * `applySettlement()` reads the current open balance, subtracts the
 * delta (positive = pays down the balance), computes the new state,
 * and writes both columns inside a single transaction. The pragma
 * `busy_timeout = 5000` is the SQLite equivalent of "wait up to five
 * seconds for a competing writer before bailing"; combined with
 * Laravel's default transaction wrapper this gives the wave its
 * read-then-write atomicity without depending on
 * `SELECT ... FOR UPDATE` (a no-op on SQLite).
 *
 * The `$user` argument scopes every read and write — a settlement
 * dispatched against another user's statement raises a RuntimeException
 * and leaves the row untouched.
 *
 * State transition rules (locked verbatim):
 *
 *   - `abs(newOpen) <= 1` (within ±€0.01)       → 'settled'
 *   - `newOpen < -1`                            → 'overpaid'
 *   - `newOpen > 0` AND `prevOpen > newOpen`    → 'partially_settled'
 *   - default                                   → previous state (no-op)
 */
final class CardStatementStateMachine
{
    /**
     * The ±€0.01 tolerance around zero for the settled state. SQLite
     * decimal rounding plus the EUR-only rounding step in the Phase 3
     * ICS adapter can produce a one-cent residual that should still
     * count as a fully settled statement.
     */
    private const SETTLED_TOLERANCE_MINOR = 1;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function applySettlement(int $statementId, int $deltaMinor, User $user): StatementSettlement
    {
        $connection = $this->db->connection();

        /** @var StatementSettlement $result */
        $result = $connection->transaction(function () use ($connection, $statementId, $deltaMinor, $user): StatementSettlement {
            // Promote SQLite's wait-for-writer fence — Laravel opens the
            // transaction in DEFERRED mode by default; the pragma asks
            // SQLite to wait up to 5 seconds for a competing writer
            // before raising SQLITE_BUSY rather than failing instantly.
            $connection->statement('PRAGMA busy_timeout = 5000');

            $row = $connection->table('card_statements')
                ->where('id', $statementId)
                ->where('user_id', $user->id)
                ->first(['id', 'open_balance_minor', 'state']);

            if ($row === null) {
                throw new RuntimeException(
                    "card_statement {$statementId} not found for user {$user->id}"
                );
            }

            $prevOpen = self::toInt($row->open_balance_minor);
            $prevState = self::toString($row->state);
            $newOpen = $prevOpen - $deltaMinor;
            $newState = match (true) {
                abs($newOpen) <= self::SETTLED_TOLERANCE_MINOR => 'settled',
                $newOpen < -self::SETTLED_TOLERANCE_MINOR => 'overpaid',
                $newOpen > 0 && $prevOpen > $newOpen => 'partially_settled',
                default => $prevState,
            };

            $now = $this->clock->now()->toDateTimeString();
            $connection->table('card_statements')
                ->where('id', $statementId)
                ->where('user_id', $user->id)
                ->update([
                    'open_balance_minor' => $newOpen,
                    'state' => $newState,
                    'updated_at' => $now,
                ]);

            return new StatementSettlement(
                statementId: $statementId,
                previousOpenMinor: $prevOpen,
                newOpenMinor: $newOpen,
                newState: $newState,
            );
        });

        return $result;
    }

    /**
     * Numeric coercion for raw query-builder column values. SQLite
     * returns scalars as strings via stdClass attributes; the strict-
     * rules `cast.int` lint stays happy by guarding the int cast.
     */
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * String coercion mirroring ThisPeriodAtAGlanceQuery::toString().
     */
    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (string) (is_scalar($value) ? $value : '');
    }
}
