<?php

declare(strict_types=1);

namespace Modules\Chains\Internal;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * Shared INSERT path for chain_links rows.
 *
 * Both IcsSettlementResolver (Wave 2) and PaypalFundingResolver (Wave 3)
 * call this helper at every chain_links write site so the evidence
 * column is json_encoded byte-identically across resolvers — a single
 * encoding policy in one place prevents per-resolver drift on the
 * UNESCAPED_UNICODE / UNESCAPED_SLASHES flags.
 *
 * The helper also folds in the pre-insert pair-uniqueness guard that
 * keeps re-running the resolver idempotent: if any chain_link already
 * exists for the (user_id, from_transaction_id, to_transaction_id,
 * kind) tuple regardless of state, the insert is skipped. That
 * mechanism is what makes rejected-pair non-re-proposal work — a row
 * the user manually rejected stays rejected because the resolver's
 * pre-insert guard refuses to write a fresh candidate for the same
 * pair.
 *
 * NULL-endpoint handling: when `to_transaction_id` is NULL (the
 * exceeded-tolerance ics_bulk_settle candidate case allowed by the
 * Wave 1 schema trigger), the existence query uses `whereNull()` so
 * the pair-uniqueness check binds the NULL-endpoint variant exactly
 * once per (user, from, kind) tuple.
 *
 * @internal Resolvers only — kept under Internal because no public
 *           caller has a legitimate reason to insert chain_links rows
 *           directly. The Public review-queue actions (ConfirmChainLink
 *           / RejectChainLink) UPDATE existing rows; they never INSERT.
 */
final class ChainLinkInsertHelper
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     *                                     Required keys: from_transaction_id, kind, state, confidence, resolver,
     *                                     evidence (array). Optional: to_transaction_id (int|null — NULL only legal
     *                                     for exceeded-tolerance ics_bulk_settle candidates per Wave 1 schema rule).
     *                                     `user_id` is sourced from the $user argument; any user_id in $row is
     *                                     ignored to keep the cross-user-safety invariant single-sourced.
     * @return bool true when a new row was inserted, false when the (from,
     *              to, kind, user) tuple already had a row in any state.
     */
    public function insertIfNotExists(array $row, User $user): bool
    {
        $connection = $this->db->connection();

        $existsQuery = $connection
            ->table('chain_links')
            ->where('user_id', $user->id)
            ->where('from_transaction_id', $row['from_transaction_id'])
            ->where('kind', $row['kind']);

        $toTxId = $row['to_transaction_id'] ?? null;
        if ($toTxId === null) {
            $existsQuery->whereNull('to_transaction_id');
        } else {
            $existsQuery->where('to_transaction_id', $toTxId);
        }

        if ($existsQuery->exists()) {
            return false;
        }

        $now = $this->clock->now()->toDateTimeString();
        $evidence = $row['evidence'] ?? [];
        $encoded = json_encode(
            $evidence,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if ($encoded === false) {
            // json_encode returns false on encoding failure (e.g. a
            // resource value sneaking into the evidence array). Loud
            // failure surfaces the bug at write time rather than
            // silently writing the empty string into a NOT NULL column.
            throw new \RuntimeException('Failed to json_encode chain_links.evidence');
        }

        $connection->table('chain_links')->insert([
            'user_id' => $user->id,
            'from_transaction_id' => $row['from_transaction_id'],
            'to_transaction_id' => $toTxId,
            'kind' => $row['kind'],
            'state' => $row['state'],
            'confidence' => $row['confidence'],
            'resolver' => $row['resolver'],
            'evidence' => $encoded,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }
}
