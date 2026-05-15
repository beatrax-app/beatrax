<?php

declare(strict_types=1);

namespace Modules\Transfers\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Events\TransactionImported;
use RuntimeException;

/**
 * Deterministic Layer-1 pair-detection listener (D-73 / D-75).
 *
 * Subscribes to `TransactionImported` (fired synchronously inside
 * `RecordTransactions`' outer DB transaction) and runs a single,
 * deterministic match for every newly-inserted transfer_in / transfer_out
 * row:
 *
 *   - same user (cross-user pairing is impossible — every query filters
 *     on $user->id, T-04-W2-01/02 mitigation)
 *   - counterparty_iban matches one of the user's own Account.iban rows
 *     (synthetic IBANs `'ICS-CARD'` and `'PAYPAL'` participate as
 *      ordinary Account.iban values)
 *   - amount equal-and-opposite, same currency
 *   - booked_at within ±3 days (D-73 tolerance)
 *   - both legs typed transfer_in / transfer_out
 *   - neither leg already paired (defensive — the listener is idempotent
 *     on re-fire)
 *
 * When matched, BOTH rows get `pair_transaction_id` written to point at
 * each other inside the same handle() call. The listener inherits the
 * outer `RecordTransactions` DB transaction frame so the symmetric write
 * is atomic — no nested DB::transaction() wrapper (Pitfall 1).
 *
 * The listener is intentionally NOT `ShouldHandleEventsAfterCommit` and
 * NOT `ShouldQueue`: same-import-batch pair-detection requires observing
 * partner rows that were inserted earlier in the same outer transaction.
 *
 * The listener LINKS — it never re-types rows. Type assignment is the
 * `ClassifyTransactionType` pipeline stage's job; the listener only
 * looks for already-typed transfer legs to connect.
 *
 * Account + partner lookups use the raw `DatabaseManager` query builder
 * rather than Eloquent because the project applies
 * `phpstan-strict-rules`' `staticMethod.dynamicCall` rule (which forbids
 * `Eloquent\Builder::whereBetween()`, `whereIn()`, `orderBy()`, etc.).
 * The matching Eloquent model is loaded only once a partner id is
 * resolved, so the symmetric save() pair is still an Eloquent write.
 */
final class PairTransferCandidates
{
    /**
     * ±3-day window per D-73. A constant so the tolerance is greppable
     * + adjustable in one place.
     */
    private const WINDOW_DAYS = 3;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function handle(TransactionImported $event): void
    {
        // Defensive: refuse to pair when the event payload's user does
        // not match the transaction's owning user (T-04-W2-02). The
        // event is in-process / in-transaction so this should never
        // happen in production — surfacing as an exception means any
        // future regression in event construction trips fast and loud.
        if ($event->transaction->user_id !== $event->user->id) {
            throw new RuntimeException(
                'TransactionImported.user.id does not match transaction.user_id — refusing to pair.'
            );
        }

        $tx = $event->transaction;
        $user = $event->user;

        // Layer-1 only fires when the row is itself a transfer leg.
        if (! in_array($tx->type, ['transfer_out', 'transfer_in'], true)) {
            return;
        }

        // Defensive: skip rows already paired (re-fire after pairing is
        // a no-op).
        if ($tx->pair_transaction_id !== null) {
            return;
        }

        // The counterparty IBAN must be present to identify the partner
        // account. Synthetic IBANs ('ICS-CARD', 'PAYPAL') participate
        // here as ordinary Account.iban values.
        if ($tx->counterparty_iban === null || $tx->counterparty_iban === '') {
            return;
        }

        $connection = $this->db->connection();

        // Look up the partner account by IBAN — scoped to the same
        // user. Raw query builder per the staticMethod.dynamicCall
        // rule.
        $partnerAccountRow = $connection
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('iban', $tx->counterparty_iban)
            ->first(['id']);
        if ($partnerAccountRow === null) {
            return;
        }
        $partnerAccountId = self::toInt($partnerAccountRow->id ?? null);

        // Normalise to whole-day boundaries so the window is symmetric
        // ±WINDOW_DAYS calendar days regardless of the row's time-of-day
        // (different adapters book at different times: ASN at 12:00:00,
        // PayPal at startOfDay, etc.).
        $windowStart = $tx->booked_at->copy()->startOfDay()->subDays(self::WINDOW_DAYS);
        $windowEnd = $tx->booked_at->copy()->endOfDay()->addDays(self::WINDOW_DAYS);

        // Partner query — uses the partial index from the migration:
        //
        //   CREATE INDEX transactions_unpaired_transfer_idx
        //   ON transactions(user_id, account_id, booked_at)
        //   WHERE pair_transaction_id IS NULL
        //     AND type IN ('transfer_out', 'transfer_in')
        //
        // The where('id', '!=', $tx->id) clause is the self-pair
        // safeguard for the degenerate case where a row's counterparty
        // IBAN matches its own account's IBAN.
        $partnerRow = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $partnerAccountId)
            ->where('amount_minor', -$tx->amount_minor)
            ->where('currency', $tx->currency)
            ->whereBetween('booked_at', [
                $windowStart->toDateTimeString(),
                $windowEnd->toDateTimeString(),
            ])
            ->whereNull('pair_transaction_id')
            ->whereIn('type', ['transfer_out', 'transfer_in'])
            ->where('id', '!=', $tx->id)
            ->orderBy('booked_at')
            ->first(['id']);

        if ($partnerRow === null) {
            // Half-pair state per D-74. Partner may land later — when
            // it does, that import's listener event will find this
            // (still-unpaired) row and close the pair.
            return;
        }
        $partnerId = self::toInt($partnerRow->id ?? null);

        // Symmetric write: BOTH sides get pair_transaction_id set in
        // the same handle() call. The two raw updates inherit the
        // outer RecordTransactions transaction frame — no nested
        // DB::transaction() wrapper.
        //
        // Raw `update()` (rather than re-loading the partner through
        // Eloquent and calling save()) skips the extra SELECT round-
        // trip: the partner id was already returned by the
        // partner-lookup query above. The user_id predicate reasserts
        // user scoping belt-and-braces.
        $now = $this->clock->now()->toDateTimeString();

        $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('id', $tx->id)
            ->update([
                'pair_transaction_id' => $partnerId,
                'updated_at' => $now,
            ]);
        $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('id', $partnerId)
            ->update([
                'pair_transaction_id' => $tx->id,
                'updated_at' => $now,
            ]);

        // Keep the event payload's in-memory model in sync with the
        // persisted change so downstream listeners observing the same
        // event see the post-pair state.
        $tx->pair_transaction_id = $partnerId;
        $tx->syncOriginalAttribute('pair_transaction_id');
    }

    /**
     * Numeric coercion for raw query-builder column values. Matches the
     * shape used by `TopCategoriesByPeriodQuery::toInt()` so PHPStan
     * strict-rules' `cast.int` rule stays satisfied.
     */
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
