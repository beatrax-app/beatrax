<?php

declare(strict_types=1);

namespace Modules\Transfers\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

/**
 * Public read API over `transactions.pair_transaction_id`.
 *
 * Phase 4 introduced `pair_transaction_id` as a self-FK and the
 * `PairTransferCandidates` listener writes the symmetric link inside
 * the import transaction. Phase 5 needs a read-side counterpart
 * usable from outside the Transfers module: the chain resolver
 * inspects the existing pair before deciding whether a
 * `chain_links.kind='paypal_funding'` row should add another funder
 * leg or stop at the partner row already recorded by the Phase 4
 * listener.
 *
 * Read-only. Never writes `pair_transaction_id` — that responsibility
 * remains with the Transfers internal listener.
 *
 * Both methods scope every query on `user_id` first so cross-user
 * access is structurally impossible.
 */
final class PairLookup
{
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

    /**
     * Numeric coercion for raw query-builder column values. Mirrors the
     * shape used by Transfers' internal listener so PHPStan strict-rules'
     * `cast.int` rule stays satisfied.
     */
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
