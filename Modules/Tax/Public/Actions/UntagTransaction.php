<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Actions;

use Illuminate\Database\DatabaseManager;

/**
 * Removes the tax tag for a transaction.
 *
 * Fire-and-forget: silently no-ops when the tag does not exist or belongs
 * to a different user (0 rows deleted, no exception). This matches the
 * lifecycle-no-op convention (GoalWriter lifecycle methods).
 *
 * Uses raw DatabaseManager only — no Eloquent statics.
 */
final class UntagTransaction
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function execute(int $userId, int $transactionId): void
    {
        $this->db->connection()
            ->table('tax_transaction_tags')
            ->where('user_id', $userId)
            ->where('transaction_id', $transactionId)
            ->delete();
    }
}
