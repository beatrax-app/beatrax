<?php

declare(strict_types=1);

namespace Modules\Transfers\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;

/**
 * @link ../../../../.docs/features/transfers/architecture.md
 */
final class PairLookup
{
    use CoercesScalars;

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function isPaired(int $txId, User $user): bool
    {
        return $this->db->connection()
            ->table('transactions')
            ->where('id', $txId)
            ->where('user_id', $user->id)
            ->whereNotNull('pair_transaction_id')
            ->exists();
    }

    public function partnerId(int $txId, User $user): ?int
    {
        $row = $this->db->connection()
            ->table('transactions')
            ->where('id', $txId)
            ->where('user_id', $user->id)
            ->first(['pair_transaction_id']);

        if ($row === null || $row->pair_transaction_id === null) {
            return null;
        }

        return self::toInt($row->pair_transaction_id);
    }
}
