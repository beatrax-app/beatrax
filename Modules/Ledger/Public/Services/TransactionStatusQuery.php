<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Ledger\Public\Enums\ClearedStatus;

final readonly class TransactionStatusQuery
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    // A missing or cross-user id returns false, so callers treat an
    // unknown row as unlocked — the subsequent user-scoped write is
    // itself the authoritative ownership gate.
    public function isReconciled(int $userId, int $transactionId): bool
    {
        return self::locksEdits(
            $this->db->connection()
                ->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $userId)
                ->value('status'),
        );
    }

    // The one comparison of a stored status against the edit-lock state. A
    // caller that already holds the row asks here rather than re-reading it,
    // which is what kept thirteen copies of the literal in step with each other.
    public static function locksEdits(mixed $status): bool
    {
        return is_string($status) && ClearedStatus::tryFrom($status) === ClearedStatus::Reconciled;
    }

    /**
     * @param  array<int>  $ids
     * @return list<int>
     */
    public function reconciledIdsAmong(int $userId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->where('status', ClearedStatus::Reconciled->value)
            ->pluck('id')
            ->all();

        $result = [];
        foreach ($rows as $id) {
            if (is_numeric($id)) {
                $result[] = (int) $id;
            }
        }

        return $result;
    }
}
