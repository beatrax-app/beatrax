<?php

declare(strict_types=1);

namespace Modules\Transfers\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;

/**
 * Public contract for the deterministic transfer-leg matcher.
 *
 * Two entry points:
 *
 *  - `pairOne()` matches a single freshly-imported leg against its
 *    partner — the per-row hot path used by the `TransactionImported`
 *    listener inside the import transaction frame.
 *
 *  - `pairOrphansForUser()` sweeps every unpaired `transfer_in` /
 *    `transfer_out` row for the user and pairs whatever the per-row
 *    matcher would have. Consumed by the chain-resolution job after
 *    its RetypeByAliasResolver has flipped any `expense` / `income`
 *    rows that the wizard-order race left mis-typed — those retyped
 *    rows missed the `TransactionImported` event and therefore never
 *    traversed the listener, so the orphan-sweep is the only path to
 *    closing their pairs after the fact.
 *
 * Both methods scope every read and write on the supplied user; cross-
 * user pairing is structurally impossible.
 */
interface PairsTransferLegs
{
    /**
     * Pair a single transaction against its partner leg, if one exists.
     * Idempotent — re-firing for an already-paired row is a no-op.
     *
     * @return int|null The partner transaction id when a pair was
     *                  formed; null when the row is not a transfer
     *                  leg, is already paired, or no partner is yet
     *                  persisted (half-pair state — the partner's own
     *                  pair pass will close the loop when it lands).
     */
    public function pairOne(Transaction $tx, User $user): ?int;

    /**
     * Sweep unpaired transfer legs for the user, pairing each one that
     * has a partner now persisted. Idempotent — a re-run finds zero
     * orphans because the previous pass already paired them.
     *
     * @return int The number of NEW pair links written. A pair counts
     *             once (the symmetric write is a single logical link).
     */
    public function pairOrphansForUser(User $user): int;
}
