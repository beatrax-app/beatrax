<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Ledger\Public\Enums\ClearedStatus;

final class TransactionStatusQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    // A missing or cross-user id returns false, so callers treat an
    // unknown row as unlocked — the subsequent user-scoped write is
    // itself the authoritative ownership gate.
    public function isReconciled(int $userId, int $transactionId): bool
    {
        return $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->value('status') === ClearedStatus::Reconciled->value;
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
