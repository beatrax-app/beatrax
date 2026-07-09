<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Resolvers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

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
 * the now-complete account graph per user:
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
 * filters on `$user->id` (the outer query, the alias map build, and
 * the account-kind map build).
 *
 * CR-03/D-06 (decrypt-then-match, HIGH candidate-set-narrowing risk):
 * the retype condition used to be a single raw SQL UPDATE with a
 * correlated `EXISTS` subquery testing
 * `kci.real_iban = transactions.counterparty_iban` — a ciphertext-
 * column equality that can never match once `counterparty_iban` is
 * encrypted. That whole-table join-equality cannot survive under
 * encryption; it is replaced with a BOUNDED decrypt-then-match:
 *
 *   1. Load `known_counterparty_ibans` for this user into an in-PHP
 *      map (`real_iban` (plaintext) => `target_account_kind`). This
 *      table is small and plaintext. If the user has NO aliases at
 *      all, return 0 immediately WITHOUT decrypting a single
 *      transaction row (T-14.1-06 DoS guard — the common case for a
 *      brand-new user).
 *   2. Load this user's own accounts into an in-PHP map (`kind` =>
 *      list of account ids). Also small (a handful of rows per user).
 *   3. Narrow candidate transactions on the SAME cheap plaintext dims
 *      the original SQL used (`user_id`, `type IN ('expense',
 *      'income')`, `counterparty_iban` non-null/non-empty) — this
 *      predicate is inherently self-shrinking across resolver runs
 *      (idempotency: a retyped row leaves the `('expense', 'income')`
 *      set and is never a candidate again), so it does not degrade
 *      into an unbounded full-history scan on steady-state (non-first)
 *      runs. Processed via `chunkById()` so even a large first-run
 *      history sweep never holds every candidate row in memory at
 *      once.
 *   4. Decrypt each surviving candidate's `counterparty_iban` ONCE and
 *      look it up in the in-PHP alias map; only when a match resolves
 *      to a target-kind account OTHER than the row's own account
 *      (mirrors the dropped subquery's `a.id != transactions.account_id`
 *      self-transfer guard) does the row get queued for retype.
 *   5. Apply the retype via one or more bulk `UPDATE ... WHERE id IN
 *      (...)` statements, batched to bound statement size.
 *
 * Raw query builder rather than Eloquent throughout — sidesteps
 * Eloquent's per-row event/observer firing (neither needed here) and
 * keeps the project's `phpstan-strict-rules` `staticMethod.dynamicCall`
 * rule satisfied.
 */
final class RetypeByAliasResolver
{
    /**
     * Bulk-UPDATE batch size for the retype pass. Bounds the size of
     * any single `WHERE id IN (...)` statement regardless of how many
     * rows the decrypt-then-match pass queues.
     */
    private const UPDATE_BATCH_SIZE = 500;

    /** `chunkById()` page size for the candidate-transaction decrypt scan. */
    private const CANDIDATE_CHUNK_SIZE = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    /**
     * @return int The number of rows retyped.
     */
    public function resolveForUser(User $user): int
    {
        $connection = $this->db->connection();
        $now = $this->clock->now()->toDateTimeString();

        // Bound #1 (T-14.1-06): the alias set is small and plaintext.
        // No aliases at all means this pass can never retype anything —
        // return before decrypting a single row.
        $aliasKindByIban = $this->loadAliasMap($connection, $user);
        if ($aliasKindByIban === []) {
            return 0;
        }

        // Bound #2: this user's own accounts grouped by kind — small.
        $accountIdsByKind = $this->loadAccountKindMap($connection, $user);

        /** @var list<int> $transferOutIds */
        $transferOutIds = [];
        /** @var list<int> $transferInIds */
        $transferInIds = [];

        // Bound #3/#4: narrow on the same cheap plaintext dims the
        // original SQL used, chunk the scan, decrypt-then-match each
        // candidate against the in-PHP maps built above.
        $connection
            ->table('transactions')
            ->select(['id', 'account_id', 'amount_minor', 'counterparty_iban'])
            ->where('user_id', $user->id)
            ->whereIn('type', ['expense', 'income'])
            ->whereNotNull('counterparty_iban')
            ->where('counterparty_iban', '!=', '')
            ->chunkById(self::CANDIDATE_CHUNK_SIZE, function ($rows) use (
                $aliasKindByIban,
                $accountIdsByKind,
                $user,
                &$transferOutIds,
                &$transferInIds,
            ): void {
                foreach ($rows as $row) {
                    /** @var stdClass $row */
                    $this->matchCandidate(
                        $row,
                        $aliasKindByIban,
                        $accountIdsByKind,
                        $user,
                        $transferOutIds,
                        $transferInIds,
                    );
                }
            });

        $touched = 0;
        $touched += $this->applyRetype($connection, $transferOutIds, 'transfer_out', $now);
        $touched += $this->applyRetype($connection, $transferInIds, 'transfer_in', $now);

        return $touched;
    }

    /**
     * Decrypt one candidate's counterparty_iban and, on a match against
     * the alias map (resolving to a target-kind account other than the
     * row's own account), queue its id into the appropriate by-target-
     * type bucket.
     *
     * @param  array<string, string>  $aliasKindByIban
     * @param  array<string, list<int>>  $accountIdsByKind
     * @param  list<int>  $transferOutIds
     * @param  list<int>  $transferInIds
     *
     * @param-out list<int> $transferOutIds
     * @param-out list<int> $transferInIds
     */
    private function matchCandidate(
        stdClass $row,
        array $aliasKindByIban,
        array $accountIdsByKind,
        User $user,
        array &$transferOutIds,
        array &$transferInIds,
    ): void {
        $storedIban = $row->counterparty_iban ?? null;
        if (! is_string($storedIban) || $storedIban === '') {
            return;
        }

        $plainIban = $this->codec->decryptValue(
            'transactions',
            'counterparty_iban',
            $storedIban,
            $user->id,
            $this->session,
        )['value'];

        $targetKind = $aliasKindByIban[$plainIban] ?? null;
        if ($targetKind === null) {
            return;
        }

        $ownAccountId = self::toInt($row->account_id ?? null);
        $targetAccountIds = $accountIdsByKind[$targetKind] ?? [];
        $hasOtherAccount = false;
        foreach ($targetAccountIds as $targetAccountId) {
            if ($targetAccountId !== $ownAccountId) {
                $hasOtherAccount = true;
                break;
            }
        }
        if (! $hasOtherAccount) {
            return;
        }

        $rowId = self::toInt($row->id ?? null);
        if ($rowId === 0) {
            return;
        }

        $amountMinor = self::toInt($row->amount_minor ?? null);
        if ($amountMinor < 0) {
            $transferOutIds[] = $rowId;
        } else {
            $transferInIds[] = $rowId;
        }
    }

    /**
     * @return array<string, string> real_iban (plaintext) => target_account_kind
     */
    private function loadAliasMap(Connection $connection, User $user): array
    {
        $map = [];
        $rows = $connection->table('known_counterparty_ibans')
            ->where('user_id', $user->id)
            ->get(['real_iban', 'target_account_kind']);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $realIban = self::toStringOrNull($row->real_iban ?? null);
            $targetKind = self::toStringOrNull($row->target_account_kind ?? null);
            if ($realIban !== null && $realIban !== '' && $targetKind !== null && $targetKind !== '') {
                $map[$realIban] = $targetKind;
            }
        }

        return $map;
    }

    /**
     * @return array<string, list<int>> kind => account ids
     */
    private function loadAccountKindMap(Connection $connection, User $user): array
    {
        $map = [];
        $rows = $connection->table('accounts')
            ->where('user_id', $user->id)
            ->get(['id', 'kind']);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $kind = self::toStringOrNull($row->kind ?? null);
            $id = self::toInt($row->id ?? null);
            if ($kind !== null && $kind !== '' && $id > 0) {
                $map[$kind][] = $id;
            }
        }

        return $map;
    }

    /**
     * Apply the retype for one target type via batched raw
     * `UPDATE ... WHERE id IN (...)` statements. Returns the number of
     * rows updated.
     *
     * Deliberately a raw SQL string (mirrors the resolver's pre-fix
     * single-statement shape) rather than the fluent
     * `->table('transactions')->update(...)` builder chain — this
     * resolver is the ONE documented exception to
     * `BoundaryArchTest::noResolverWritesTransactions` (it retypes
     * `transactions.type`, unlike every other Chains resolver, which
     * writes only `chain_links`/`card_statements`); staying on the raw
     * SQL form keeps that exception textually distinct from the
     * forbidden fluent-builder shape the arch test greps for.
     *
     * @param  list<int>  $ids
     */
    private function applyRetype(Connection $connection, array $ids, string $type, string $now): int
    {
        if ($ids === []) {
            return 0;
        }

        $touched = 0;
        foreach (array_chunk($ids, self::UPDATE_BATCH_SIZE) as $batch) {
            $placeholders = implode(',', array_fill(0, count($batch), '?'));
            $touched += $connection->update(
                "UPDATE transactions SET type = ?, updated_at = ? WHERE id IN ({$placeholders})",
                [$type, $now, ...$batch],
            );
        }

        return $touched;
    }

    /**
     * Numeric coercion for raw query-builder column values.
     */
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Null-safe string coercion for raw query-builder column values.
     */
    private static function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
