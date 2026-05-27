<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Resolvers;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * Post-import retype-by-alias healing pass.
 *
 * Resolves the wizard-order race: when the onboarding flow uploads a
 * source file BEFORE the user has created the destination-kind account
 * (e.g. ASN CSV uploaded while only the `bank`-kind account exists,
 * with the `paypal`-kind account created moments later in a subsequent
 * wizard step), the preview-time ClassifyTransactionType pipeline stage
 * (Import module) finds the alias row but no matching destination
 * Account, so
 * the row falls through to the amount-sign default (`expense` /
 * `income`). The cached canonical persists that type into the ledger
 * on confirm. Without a healing pass those rows remain mis-typed
 * forever, every downstream chain resolver iterates an empty set, and
 * `chain_links` stays empty.
 *
 * The resolver re-applies the classifier's cross-account rule against
 * the now-complete account graph as a single bulk SQL UPDATE per user:
 *
 *   - target rows: `type IN ('expense', 'income')` AND
 *     `counterparty_iban` non-null AND non-empty
 *   - retype condition: the counterparty IBAN appears in
 *     `known_counterparty_ibans` for this user AND the alias's
 *     `target_account_kind` resolves to an account belonging to the
 *     same user AND that account is NOT the row's own account
 *   - new type: amount-sign rule — `amount_minor < 0` →
 *     `transfer_out`, otherwise `transfer_in`
 *
 * Idempotent — a second pass matches zero rows because the previous
 * pass already flipped them out of `'expense' | 'income'`. Self-healing
 * — when the user adds a new known-counterparty alias later, the next
 * chain dispatch retypes any historical rows that match it.
 *
 * Run BEFORE the per-row pair-orphan sweep + the two existing chain
 * resolvers inside ResolveChainLinksJob so the downstream resolvers
 * iterate the corrected ledger.
 *
 * The retype does NOT touch `pair_transaction_id` — pairing is the
 * orphan-sweep's responsibility. Cross-user safety: every predicate
 * filters on `$user->id` (the outer query, the alias subquery, and
 * the account-existence subquery).
 *
 * Raw query builder rather than Eloquent because the bulk UPDATE pattern
 * sidesteps Eloquent's per-row event/observer firing — neither needed
 * here, and skipping them keeps the cost O(1) regardless of the row
 * count touched.
 */
final class RetypeByAliasResolver
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @return int The number of rows retyped.
     */
    public function resolveForUser(User $user): int
    {
        $connection = $this->db->connection();
        $now = $this->clock->now()->toDateTimeString();

        // Single bulk UPDATE — atomic, idempotent, O(1) PHP regardless
        // of row count. The CASE expression encodes the amount-sign
        // rule directly (no per-row PHP loop). SQLite + MySQL both
        // accept the correlated subquery shape via the query builder
        // because raw expressions are inserted verbatim. The two
        // bound :uid params get rebound per execution; the third
        // appears inline in the table-qualified column references
        // (no parameter needed for them).
        return $connection->update(
            <<<'SQL'
            UPDATE transactions
            SET type = CASE WHEN amount_minor < 0 THEN 'transfer_out' ELSE 'transfer_in' END,
                updated_at = ?
            WHERE user_id = ?
              AND type IN ('expense', 'income')
              AND counterparty_iban IS NOT NULL
              AND counterparty_iban != ''
              AND EXISTS (
                SELECT 1
                FROM known_counterparty_ibans kci
                INNER JOIN accounts a
                  ON a.user_id = kci.user_id
                  AND a.kind = kci.target_account_kind
                WHERE kci.user_id = ?
                  AND kci.real_iban = transactions.counterparty_iban
                  AND a.id != transactions.account_id
              )
            SQL,
            [$now, $user->id, $user->id],
        );
    }
}
