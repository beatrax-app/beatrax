<?php

declare(strict_types=1);

namespace Modules\Transfers\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Transaction;
use RuntimeException;
use stdClass;

/**
 * Deterministic pair-detection listener that links transfer legs.
 *
 * Subscribes to `TransactionImported` (fired synchronously inside
 * `RecordTransactions`' outer DB transaction) and runs a single,
 * deterministic match for every newly-inserted transfer_in /
 * transfer_out row:
 *
 *   - same user (cross-user pairing is impossible — every query filters
 *     on $user->id)
 *   - amount equal-and-opposite, same currency
 *   - booked_at within ±WINDOW_DAYS calendar days
 *   - both legs typed transfer_in / transfer_out
 *   - neither leg already paired (defensive — the listener is
 *     idempotent on re-fire)
 *
 * The IBAN reconciliation walks both directions so the pair forms
 * regardless of which leg imports first:
 *
 *   - Forward direction (firing leg has a counterparty_iban): the
 *     counterparty_iban matches one of the user's own Account.iban
 *     rows (synthetic IBANs `'ICS-CARD'` and `'PAYPAL'` participate
 *     as ordinary Account.iban values) OR resolves to the user's
 *     own Account via `ResolvesKnownCounterpartyIban` (the known-
 *     counterparty-IBAN alias bridge that maps real institution
 *     IBANs such as PayPal SARL Luxembourg's `LU89751000135104200E`
 *     or ICS at ABN AMRO's `NL08ABNA0526650664` to the user's
 *     synthetic-IBAN accounts of the matching kind).
 *
 *   - Reverse direction (firing leg has NO counterparty_iban — the
 *     empirical PayPal Activity Download CSV ships the funding-leg
 *     parent `Bankstorting` row without a per-row counterparty
 *     IBAN): the listener computes the IBAN set that points AT the
 *     firing leg's own account (the literal account.iban plus every
 *     known-counterparty alias whose `target_account_kind` matches
 *     the firing account's kind) and looks for an unpaired equal-
 *     and-opposite transfer leg on a different account whose
 *     counterparty_iban sits in that set. The reverse direction is
 *     the only route to a pair when the PayPal-side leg lands
 *     AFTER the ASN-side leg.
 *
 * When matched, BOTH rows get `pair_transaction_id` written to point
 * at each other inside the same handle() call. The listener inherits
 * the outer `RecordTransactions` DB transaction frame so the symmetric
 * write is atomic — no nested DB::transaction() wrapper.
 *
 * The listener is intentionally NOT `ShouldHandleEventsAfterCommit`
 * and NOT `ShouldQueue`: same-import-batch pair-detection requires
 * observing partner rows that were inserted earlier in the same outer
 * transaction.
 *
 * The listener LINKS — it never re-types rows. Type assignment is the
 * `ClassifyTransactionType` pipeline stage's job; the listener only
 * looks for already-typed transfer legs to connect.
 *
 * Account + partner lookups use the raw `DatabaseManager` query
 * builder rather than Eloquent because the project applies
 * `phpstan-strict-rules`' `staticMethod.dynamicCall` rule (which
 * forbids `Eloquent\Builder::whereBetween()`, `whereIn()`,
 * `orderBy()`, etc.). The symmetric write also uses raw `update()`
 * statements so no extra Eloquent re-fetch is required to seat the
 * partner row.
 */
final class PairTransferCandidates
{
    /**
     * Symmetric ±WINDOW_DAYS calendar-day tolerance for the
     * booked_at-based partner search. A constant so the tolerance is
     * greppable and adjustable in one place.
     */
    private const WINDOW_DAYS = 3;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly ResolvesKnownCounterpartyIban $aliasResolver,
    ) {}

    public function handle(TransactionImported $event): void
    {
        // Defensive: refuse to pair when the event payload's user does
        // not match the transaction's owning user. The event is in-
        // process / in-transaction so this should never happen in
        // production — surfacing as an exception means any future
        // regression in event construction trips fast and loud.
        if ($event->transaction->user_id !== $event->user->id) {
            throw new RuntimeException(
                'TransactionImported.user.id does not match transaction.user_id — refusing to pair.'
            );
        }

        $tx = $event->transaction;
        $user = $event->user;

        // Only fire when the row is itself a transfer leg.
        if (! in_array($tx->type, ['transfer_out', 'transfer_in'], true)) {
            return;
        }

        // Defensive: skip rows already paired (re-fire after pairing is
        // a no-op).
        if ($tx->pair_transaction_id !== null) {
            return;
        }

        $connection = $this->db->connection();

        // Normalise to whole-day boundaries so the window is symmetric
        // ±WINDOW_DAYS calendar days regardless of the row's time-of-day
        // (different adapters book at different times: ASN at 12:00:00,
        // PayPal at startOfDay, etc.).
        $windowStart = $tx->booked_at->copy()->startOfDay()->subDays(self::WINDOW_DAYS);
        $windowEnd = $tx->booked_at->copy()->endOfDay()->addDays(self::WINDOW_DAYS);

        // The empirical PayPal Activity Download CSV does NOT carry a
        // per-row counterparty IBAN on funding-leg parents (the
        // `Bankstorting` row). The ASN side of the same ASN→PayPal hop
        // DOES carry the real institution IBAN
        // (`LU89751000135104200E`). Either leg may import first, so
        // the listener walks BOTH directions:
        //
        //   Forward direction (firing leg has counterparty IBAN):
        //     resolve counterparty IBAN -> partner account, then
        //     match an unpaired equal-and-opposite transfer on that
        //     partner account within the window.
        //
        //   Reverse direction (firing leg has NO counterparty IBAN):
        //     compute the set of IBANs that point AT the firing leg's
        //     own account (literal account.iban + every alias whose
        //     target_account_kind matches the firing account's kind)
        //     and look for an unpaired equal-and-opposite transfer on
        //     a different account whose counterparty_iban sits in
        //     that set.
        //
        // The reverse direction is the only route to a pair when the
        // PayPal-side leg lands AFTER the ASN-side leg — the ASN side
        // would already have fired the forward search against an
        // empty ledger and written nothing.
        if ($tx->counterparty_iban === null || $tx->counterparty_iban === '') {
            $partnerRow = $this->findPartnerByReverseLookup(
                $tx,
                $user->id,
                $windowStart->toDateTimeString(),
                $windowEnd->toDateTimeString(),
            );
        } else {
            // Forward direction. Look up the partner account by IBAN
            // — scoped to the same user. Two-arm consult: Arm A
            // (literal) matches one of the user's own Account.iban
            // rows directly; Arm B (alias bridge) resolves real
            // institution IBANs to the user's own synthetic-IBAN
            // account via `ResolvesKnownCounterpartyIban`. Raw query
            // builder per the staticMethod.dynamicCall rule.
            $partnerAccountRow = $connection
                ->table('accounts')
                ->where('user_id', $user->id)
                ->where('iban', $tx->counterparty_iban)
                ->first(['id']);

            if ($partnerAccountRow !== null) {
                $partnerAccountId = self::toInt($partnerAccountRow->id ?? null);
            } else {
                // Arm B — alias bridge: real institution IBAN ->
                // user's own synthetic-IBAN account. Resolves the
                // cross-account-hop case where the source statement
                // reports the institution's real IBAN (e.g. PayPal
                // SARL Luxembourg's `LU89751000135104200E`) but the
                // matching partner account uses a synthetic own-IBAN
                // literal (`'PAYPAL'`).
                $aliasAccount = $this->aliasResolver->resolveAccount($tx->counterparty_iban, $user->id);
                if ($aliasAccount === null) {
                    return;
                }
                $partnerAccountId = $aliasAccount->id;
            }

            // Partner query — uses the partial index from the migration:
            //
            //   CREATE INDEX transactions_unpaired_transfer_idx
            //   ON transactions(user_id, account_id, booked_at)
            //   WHERE pair_transaction_id IS NULL
            //     AND type IN ('transfer_out', 'transfer_in')
            //
            // The where('id', '!=', $tx->id) clause is the self-pair
            // safeguard for the degenerate case where a row's
            // counterparty IBAN matches its own account's IBAN.
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
        }

        if ($partnerRow === null) {
            // Half-pair state. Partner may land later — when it does,
            // that import's listener event will find this (still-
            // unpaired) row and close the pair.
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
     * Reverse-direction partner lookup for the leg that has no
     * counterparty IBAN of its own (e.g. the PayPal-side
     * `transfer_in` whose `Bankstorting` parent ships an empty
     * counterparty_iban).
     *
     * Builds the set of IBANs that point AT the firing leg's own
     * account:
     *
     *   - the literal `accounts.iban` of the firing leg's account
     *     (so a partner whose `counterparty_iban` matches that
     *     literal pairs back), and
     *
     *   - every `known_counterparty_ibans.real_iban` whose
     *     `target_account_kind` matches the firing leg's account
     *     kind (so a partner whose `counterparty_iban` is the real
     *     institution IBAN that alias-resolves to the firing
     *     account pairs back).
     *
     * Then searches for an unpaired equal-and-opposite transfer leg
     * across the user's other accounts whose `counterparty_iban`
     * sits in that set, ordered by `booked_at` for determinism.
     *
     * @return stdClass|null A raw query-builder row with an `id`
     *                       attribute, or null when no partner is yet
     *                       persisted (half-state — the partner will
     *                       drive its own pair search when it lands).
     */
    private function findPartnerByReverseLookup(
        Transaction $tx,
        int $userId,
        string $windowStart,
        string $windowEnd,
    ): ?stdClass {
        $connection = $this->db->connection();

        $accountRow = $connection
            ->table('accounts')
            ->where('user_id', $userId)
            ->where('id', $tx->account_id)
            ->first(['iban', 'kind']);

        if ($accountRow === null) {
            return null;
        }

        $candidateIbans = [];

        $ownIban = self::toStringOrNull($accountRow->iban ?? null);
        if ($ownIban !== null && $ownIban !== '') {
            $candidateIbans[] = $ownIban;
        }

        $ownKind = self::toStringOrNull($accountRow->kind ?? null);
        if ($ownKind !== null && $ownKind !== '') {
            $aliasIbans = $connection
                ->table('known_counterparty_ibans')
                ->where('user_id', $userId)
                ->where('target_account_kind', $ownKind)
                ->pluck('real_iban')
                ->all();
            foreach ($aliasIbans as $aliasIban) {
                $aliasIbanStr = self::toStringOrNull($aliasIban);
                if ($aliasIbanStr !== null && $aliasIbanStr !== '') {
                    $candidateIbans[] = $aliasIbanStr;
                }
            }
        }

        if ($candidateIbans === []) {
            return null;
        }

        return $connection
            ->table('transactions')
            ->where('user_id', $userId)
            ->where('account_id', '!=', $tx->account_id)
            ->where('amount_minor', -$tx->amount_minor)
            ->where('currency', $tx->currency)
            ->whereBetween('booked_at', [$windowStart, $windowEnd])
            ->whereNull('pair_transaction_id')
            ->whereIn('type', ['transfer_out', 'transfer_in'])
            ->whereIn('counterparty_iban', $candidateIbans)
            ->where('id', '!=', $tx->id)
            ->orderBy('booked_at')
            ->first(['id']);
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

    /**
     * Null-safe string coercion for raw query-builder column values.
     * Non-string scalars become their string form; null and non-
     * scalar values become null. Keeps the strict-rules `cast.string`
     * lint happy without forcing an empty-string default that would
     * pollute the candidate-IBAN set.
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
