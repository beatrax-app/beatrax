<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;

/**
 * Minimal Public read service exposing a transaction's reconciled-lock
 * status to other modules WITHOUT reaching into Ledger internals or the
 * Eloquent `Transaction` model.
 *
 * Exists so the Tax module can enforce the D-08 reconciled lock (warn-first,
 * no write) on its cross-module tax-tag write paths — the whole-transaction
 * "Tag as tax-relevant" flow that mirrors the Ledger-side leg-level
 * `toggleLegTax` guard (review WR-01 / UAT U-1). Ledger's Internal services
 * and models are off-limits to other modules (module boundary constraint),
 * so the check has to travel through this Public seam.
 *
 * Query idiom mirrors `AccountBalanceQuery`/`HandlesClearedStatus`: raw
 * DatabaseManager only (no Eloquent statics — PHPStan level 10 strict).
 *
 * T-03-01 (Information Disclosure mitigation): every read carries an explicit
 * `->where('user_id')` guard so a foreign transaction id resolves to the
 * caller's own (empty) result — never another user's status — and a
 * cross-user id therefore reads as "not reconciled" rather than leaking
 * existence.
 */
final class TransactionStatusQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * True when the user-owned transaction is in the `reconciled` state.
     *
     * A missing or cross-user id returns false (the `value()` yields null),
     * so callers treat an unknown row as unlocked — the subsequent
     * user-scoped write is itself the authoritative ownership gate.
     */
    public function isReconciled(int $userId, int $transactionId): bool
    {
        return $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->value('status') === 'reconciled';
    }

    /**
     * Returns the subset of `$ids` (user-scoped) whose status is
     * `reconciled` — the batch-path sibling of `isReconciled()` so callers
     * can filter locked rows out of a bulk mutation in one query.
     *
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
            ->where('status', 'reconciled')
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
