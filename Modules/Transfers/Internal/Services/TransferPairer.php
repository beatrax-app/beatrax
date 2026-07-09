<?php

declare(strict_types=1);

namespace Modules\Transfers\Internal\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;
use stdClass;

/**
 * Concrete deterministic pair-detection matcher.
 *
 * Owns the single source of truth for "given a transfer leg, find and
 * link its partner". Used by:
 *
 *  - the per-row PairTransferCandidates listener on the hot path
 *    inside the import transaction frame.
 *
 *  - the Chains module's ResolveChainLinksJob on the bulk orphan-sweep,
 *    after its RetypeByAliasResolver retypes rows that the wizard's
 *    interleaved upload-then-create-account flow left mis-typed at
 *    preview time.
 *
 * Match rules (identical for both entry points):
 *
 *   - same user (every query filters on $user->id)
 *   - amount equal-and-opposite, same currency
 *   - booked_at within ±WINDOW_DAYS calendar days
 *   - both legs typed transfer_in / transfer_out
 *   - neither leg already paired
 *
 * The IBAN reconciliation walks BOTH directions so the pair forms
 * regardless of import order:
 *
 *   - Forward direction (firing leg has a counterparty_iban): the
 *     counterparty_iban matches one of the user's own Account.iban
 *     rows OR resolves to the user's own Account via
 *     {@see ResolvesKnownCounterpartyIban} (the alias bridge that
 *     maps real institution IBANs such as PayPal SARL Luxembourg's
 *     `LU89751000135104200E` or ICS at ABN AMRO's
 *     `NL08ABNA0526650664` to the user's synthetic-IBAN accounts
 *     of the matching kind).
 *
 *   - Reverse direction (firing leg has NO counterparty_iban — the
 *     empirical PayPal Activity Download CSV ships the funding-leg
 *     parent `Bankstorting` row without a per-row counterparty IBAN):
 *     compute the IBAN set that points AT the firing leg's own
 *     account (literal account.iban + every alias whose
 *     target_account_kind matches the firing account's kind) and look
 *     for an unpaired equal-and-opposite transfer leg on a different
 *     account whose counterparty_iban sits in that set.
 *
 * Account + partner lookups use the raw `DatabaseManager` query
 * builder rather than Eloquent because the project applies
 * `phpstan-strict-rules`' `staticMethod.dynamicCall` rule which forbids
 * `Eloquent\Builder::whereBetween()`, `whereIn()`, `orderBy()`, etc.
 *
 * The matcher LINKS — it never re-types rows. Type assignment belongs
 * to the Import module's ClassifyTransactionType pipeline stage on the
 * preview path, and to the Chains module's RetypeByAliasResolver on the
 * post-import healing path.
 *
 * CR-03 (D-02 — decrypt-then-match): `transactions.counterparty_iban`
 * is a `SensitiveFieldRegistry` ciphertext column under encryption, so
 * it can never be part of a SQL equality/`whereIn` predicate — a
 * ciphertext value never equals a plaintext candidate. Both arms
 * decrypt before comparing: the forward arm decrypts `$tx->counterparty_iban`
 * ONCE into a plaintext local before it is compared against plaintext
 * `accounts.iban` / handed to {@see ResolvesKnownCounterpartyIban};
 * the reverse arm keeps its existing narrow SQL predicates (amount
 * equal-and-opposite, currency, ±WINDOW_DAYS booked_at window, unpaired,
 * type) — already a tiny candidate set — then decrypts each surviving
 * candidate's `counterparty_iban` and matches it against the plaintext
 * `$candidateIbans` set in PHP (mirrors `CounterpartyIndexQuery`'s
 * decrypt-then-match template; `accounts.iban` predicates are NOT in
 * the encrypted set and stay untouched).
 */
final class TransferPairer implements PairsTransferLegs
{
    /**
     * Symmetric ±WINDOW_DAYS calendar-day tolerance for the
     * booked_at-based partner search. Greppable + adjustable in one
     * place; identical to the value PairTransferCandidates used before
     * the matcher was extracted.
     */
    private const WINDOW_DAYS = 3;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly ResolvesKnownCounterpartyIban $aliasResolver,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    public function pairOne(Transaction $tx, User $user): ?int
    {
        // Only fire when the row is itself a transfer leg.
        if (! in_array($tx->type, ['transfer_out', 'transfer_in'], true)) {
            return null;
        }

        // Defensive: skip rows already paired (re-fire after pairing is
        // a no-op).
        if ($tx->pair_transaction_id !== null) {
            return null;
        }

        $connection = $this->db->connection();

        // Normalise to whole-day boundaries so the window is symmetric
        // ±WINDOW_DAYS calendar days regardless of the row's time-of-day
        // (different adapters book at different times: ASN at 12:00:00,
        // PayPal at startOfDay, etc.).
        $windowStart = $tx->booked_at->copy()->startOfDay()->subDays(self::WINDOW_DAYS);
        $windowEnd = $tx->booked_at->copy()->endOfDay()->addDays(self::WINDOW_DAYS);

        if ($tx->counterparty_iban === null || $tx->counterparty_iban === '') {
            $partnerRow = $this->findPartnerByReverseLookup(
                $tx,
                $user->id,
                $windowStart->toDateTimeString(),
                $windowEnd->toDateTimeString(),
            );
        } else {
            // Forward direction. Two-arm consult: Arm A (literal) matches
            // one of the user's own Account.iban rows directly; Arm B
            // (alias bridge) resolves real institution IBANs to the user's
            // own synthetic-IBAN account via ResolvesKnownCounterpartyIban.
            //
            // CR-03 (D-02): $tx->counterparty_iban is ciphertext under
            // encryption — decrypt ONCE into a plaintext local before
            // either arm runs. Pass-through no-op when encryption is not
            // enabled for this user. The null/'' cases already routed to
            // the reverse-lookup branch above, so $tx->counterparty_iban
            // is guaranteed a non-empty string here.
            $plainIban = $this->codec->decryptValue(
                'transactions',
                'counterparty_iban',
                $tx->counterparty_iban,
                $user->id,
                $this->session,
            )['value'];

            $partnerAccountRow = $connection
                ->table('accounts')
                ->where('user_id', $user->id)
                ->where('iban', $plainIban)
                ->first(['id']);

            if ($partnerAccountRow !== null) {
                $partnerAccountId = self::toInt($partnerAccountRow->id ?? null);
            } else {
                $aliasAccount = $this->aliasResolver->resolveAccount($plainIban, $user->id);
                if ($aliasAccount === null) {
                    return null;
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
            return null;
        }
        $partnerId = self::toInt($partnerRow->id ?? null);

        // Symmetric write: BOTH sides get pair_transaction_id set in
        // the same call. When invoked from the listener the writes
        // inherit RecordTransactions' outer transaction frame; when
        // invoked from the chain job there is no outer transaction
        // and the two updates are independent statements — acceptable
        // because the only race would be a concurrent ResolveChainLinksJob
        // for the same user, which `ShouldBeUniqueUntilProcessing` rules
        // out via the per-user uniqueness lock.
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

        // Keep the caller-supplied in-memory model in sync with the
        // persisted change so downstream observers see the post-pair
        // state. Callers that pass a freshly-loaded model (the chain
        // orphan-sweep) get a free hydrate; callers that already hold
        // the row (the listener) avoid a re-SELECT.
        $tx->pair_transaction_id = $partnerId;
        $tx->syncOriginalAttribute('pair_transaction_id');

        return $partnerId;
    }

    public function pairOrphansForUser(User $user): int
    {
        $connection = $this->db->connection();

        // Snapshot the candidate ids once; iterating an updating result
        // set is undefined under SQLite. The where-narrows the partial
        // index transactions_unpaired_transfer_idx.
        /** @var list<int<1, max>> $candidateIds */
        $candidateIds = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('type', ['transfer_out', 'transfer_in'])
            ->whereNull('pair_transaction_id')
            ->orderBy('booked_at')
            ->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $paired = 0;
        foreach ($candidateIds as $id) {
            /** @var Transaction|null $tx */
            $tx = Transaction::query()->find($id);
            if ($tx === null) {
                continue;
            }
            // The matcher may have written to BOTH rows during a
            // previous iteration; re-check before re-issuing the lookup.
            if ($tx->pair_transaction_id !== null) {
                continue;
            }
            if ($this->pairOne($tx, $user) !== null) {
                $paired++;
            }
        }

        return $paired;
    }

    /**
     * Reverse-direction partner lookup for the leg that has no
     * counterparty IBAN of its own (e.g. the PayPal-side
     * `transfer_in` whose `Bankstorting` parent ships an empty
     * counterparty_iban).
     *
     * Builds the set of IBANs that point AT the firing leg's own
     * account (literal `accounts.iban` + every
     * `known_counterparty_ibans.real_iban` whose
     * `target_account_kind` matches the firing account's kind) and
     * searches across the user's other accounts for an unpaired
     * equal-and-opposite transfer leg whose `counterparty_iban` sits
     * in that set.
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

        // CR-03 (D-02): the whereIn('counterparty_iban', ...) equality
        // this used to run is a ciphertext column under encryption — a
        // plaintext $candidateIbans value can never equal it in SQL. The
        // narrowing predicates below (amount equal-and-opposite,
        // currency, ±WINDOW_DAYS booked_at window, unpaired, type)
        // already yield a tiny candidate set on their own (an indexed,
        // per-account-pair scan bounded by the partial index cited
        // above), so dropping the iban predicate here does not turn this
        // into a full-history scan. Each surviving candidate's
        // counterparty_iban is decrypted once and matched against
        // $candidateIbans in PHP — mirrors CounterpartyIndexQuery's
        // decrypt-then-match template.
        $candidates = $connection
            ->table('transactions')
            ->where('user_id', $userId)
            ->where('account_id', '!=', $tx->account_id)
            ->where('amount_minor', -$tx->amount_minor)
            ->where('currency', $tx->currency)
            ->whereBetween('booked_at', [$windowStart, $windowEnd])
            ->whereNull('pair_transaction_id')
            ->whereIn('type', ['transfer_out', 'transfer_in'])
            ->where('id', '!=', $tx->id)
            ->orderBy('booked_at')
            ->get(['id', 'counterparty_iban']);

        foreach ($candidates as $candidate) {
            /** @var stdClass $candidate */
            $storedCandidateIban = $candidate->counterparty_iban ?? null;
            if (! is_string($storedCandidateIban) || $storedCandidateIban === '') {
                continue;
            }
            $plainCandidateIban = $this->codec->decryptValue(
                'transactions',
                'counterparty_iban',
                $storedCandidateIban,
                $userId,
                $this->session,
            )['value'];

            if (in_array($plainCandidateIban, $candidateIbans, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Numeric coercion for raw query-builder column values. Matches the
     * shape used elsewhere in the codebase so PHPStan strict-rules'
     * `cast.int` rule stays satisfied.
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
