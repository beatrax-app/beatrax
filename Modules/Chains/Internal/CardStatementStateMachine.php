<?php

declare(strict_types=1);

namespace Modules\Chains\Internal;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Public\Dto\StatementSettlement;
use Modules\Chains\Public\Enums\CardStatementState;
use Modules\Chains\Public\Exceptions\CardStatementNotFoundException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;

/**
 * @link ../../../.docs/features/chains/architecture.md
 */
final class CardStatementStateMachine
{
    use CoercesScalars;

    // Tolerance around zero for the settled state: SQLite decimal rounding
    // plus the EUR-only rounding step in the ICS adapter can leave a
    // one-cent residual that should still count as fully settled.
    private const SETTLED_TOLERANCE_MINOR = 1;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function applySettlement(int $statementId, int $deltaMinor, User $user): StatementSettlement
    {
        $connection = $this->db->connection();

        return $connection->transaction(function () use ($connection, $statementId, $deltaMinor, $user): StatementSettlement {
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
                throw new CardStatementNotFoundException($statementId, $user->id);
            }

            $prevOpen = self::toInt($row->open_balance_minor);
            $prevState = self::toString($row->state);
            $newOpen = $prevOpen - $deltaMinor;
            $newState = match (true) {
                abs($newOpen) <= self::SETTLED_TOLERANCE_MINOR => CardStatementState::Settled->value,
                $newOpen < -self::SETTLED_TOLERANCE_MINOR => CardStatementState::Overpaid->value,
                $newOpen > 0 && $prevOpen > $newOpen => CardStatementState::PartiallySettled->value,
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
    }
}
