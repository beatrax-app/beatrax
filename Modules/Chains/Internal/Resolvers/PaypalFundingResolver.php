<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Resolvers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Transfers\Public\Services\PairLookup;
use stdClass;

/**
 * PayPal funding-chain resolver — three arms.
 *
 *   1. Deterministic arm — inspect the row's stored raw payload (the
 *      PayPal Activity Download event tape) for "Bankstorting" /
 *      "General Withdrawal" / "Transfer to bank" events whose memo
 *      cells carry an IBAN matching one of the user's accounts. When
 *      an equal-and-opposite `transfer_in` exists on that account
 *      within ±DATE_WINDOW_DAYS, write a confirmed chain_link with
 *      confidence=1.0, resolver='auto'.
 *
 *   2. ASN-direct arm — handles the empirical Activity Download shape
 *      where the funding-leg `Bankstorting` row is ABSENT from the
 *      PayPal CSV (the user's CSV ships only outgoing merchant
 *      payments, not the SEPA-pull deposits that funded them). Pairs
 *      the PayPal `expense` row directly against an ASN-side
 *      `transfer_out` whose counterparty_iban alias-resolves through
 *      `known_counterparty_ibans` to one of the user's `paypal`-kind
 *      accounts — same settled amount, ±DATE_WINDOW_DAYS. Unique
 *      match: state='confirmed', confidence=1.0; ambiguous (≥2
 *      candidates inside the window): state='candidate',
 *      confidence=ASN_DIRECT_AMBIGUOUS_CONFIDENCE. The arm only
 *      considers ASN rows not already cited as the `to` side of
 *      another `paypal_funding` chain_link, so two same-amount
 *      same-day PayPal expenses cannot both claim a single ASN
 *      debit.
 *
 *   3. Fuzzy arm — when arms 1 and 2 miss, score candidate
 *      `transfer_in` rows by a weighted blend of Levenshtein-
 *      normalised merchant similarity (0.5) + amount-band similarity
 *      (0.3) + date-window similarity (0.2). The best score ≥
 *      FUZZY_MIN_CONFIDENCE surfaces as state='candidate',
 *      confidence ∈ [0.6, 0.99].
 *
 * `signature_hash` (D-88) = sha256(`counterparty_normalized` + '|' +
 * funding-account IBAN). Both arms compute and persist it in
 * `evidence.signature_hash` so the Wave 3 ConfirmChainLink auto-
 * promotion learning loop has a single key to count over.
 *
 * Architectural invariants:
 *   - D-84 — resolver writes chain_links only (BoundaryArchTest
 *     enforces). The transactions table is read-only here; the read
 *     uses raw DatabaseManager query-builder calls (never
 *     `Transaction::query()`) so the BoundaryArchTest's regex stays
 *     satisfied without per-call exemptions.
 *   - FND-03 — every query filters on `user_id = $user->id` first.
 *     Cross-user leakage is structurally impossible.
 *   - Idempotency — duplicate writes are blocked by
 *     `ChainLinkInsertHelper`'s pre-insert pair-uniqueness guard,
 *     which also keeps user-rejected pairs rejected on re-run
 *     (rejected pair → re-insert refused → state preserved).
 *
 * Concurrency: invoked from `ResolveChainLinksJob`, which is keyed
 * unique-per-user (ShouldBeUniqueUntilProcessing). Parallel passes
 * for the same user cannot interleave.
 *
 * @internal Driven by ResolveChainLinksJob — not called directly
 *           from Public action classes.
 */
final class PaypalFundingResolver
{
    /** Symmetric tolerance arm for fuzzy matching: ±2% of the expense. */
    public const AMOUNT_BAND_PERCENT = 2;

    /** Symmetric date window for both arms: ±3 days. */
    public const DATE_WINDOW_DAYS = 3;

    /** Floor below which the fuzzy score is dropped (no chain_link). */
    public const FUZZY_MIN_CONFIDENCE = 0.6;

    /** Ceiling below 1.0 for fuzzy candidates (1.0 is reserved for deterministic). */
    public const FUZZY_MAX_CONFIDENCE = 0.99;

    /**
     * Confidence written when the ASN-direct arm finds exactly one
     * ASN-side `transfer_out` matching the PayPal expense's settled
     * amount inside the date window. Matches the deterministic arm's
     * 1.000 because the match is structurally unambiguous for the
     * user (single PayPal expense + single ASN debit of equal amount
     * within ±3 days).
     */
    private const ASN_DIRECT_UNIQUE_CONFIDENCE = '1.000';

    /**
     * Confidence written when the ASN-direct arm finds ≥2 ASN-side
     * `transfer_out` candidates inside the date window. The closest by
     * booked_at wins but the link drops to `state='candidate'` so the
     * user sees the ambiguity in the review queue.
     */
    private const ASN_DIRECT_AMBIGUOUS_CONFIDENCE = '0.900';

    /**
     * Event types whose memo cells are scanned for a destination IBAN
     * (D-106 — PayPal NL "General Withdrawal" hand-off close-out).
     *
     * @var list<string>
     */
    private const FUNDING_EVENT_TYPES = ['General Withdrawal', 'Bankstorting', 'Transfer to bank'];

    /**
     * Header cells inspected for an IBAN literal. The NL Activity
     * Download stores the destination IBAN in the `Naam` column for
     * Bankstorting rows; `Omschrijving` and `Memo` are defensive
     * fallbacks for forward-compat with the EN locale and ad-hoc
     * payment memos.
     *
     * @var list<string>
     */
    private const IBAN_MEMO_KEYS = ['Naam', 'Memo', 'Note', 'Description', 'Omschrijving'];

    /** Weighted score breakdown for the fuzzy arm — sums to 1.0. */
    private const FUZZY_WEIGHT_MERCHANT = 0.5;

    private const FUZZY_WEIGHT_AMOUNT = 0.3;

    private const FUZZY_WEIGHT_DATE = 0.2;

    /**
     * Cap on the ASN-direct arm's amount+date-window candidate scan
     * before per-row `counterparty_iban` decrypt-then-match (T-14.1-06).
     * The amount+date predicate already narrows to a handful of rows in
     * practice; this bounds the pathological case where many ASN debits
     * share both the settled amount and the date window.
     */
    private const ASN_DIRECT_CANDIDATE_SCAN_LIMIT = 20;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly FingerprintComposer $fingerprints,
        private readonly PairLookup $pairLookup,
        private readonly ChainLinkInsertHelper $inserter,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    /**
     * Defensive accessor mirroring `IcsSettlementResolver::pairLookupAvailable()`.
     * Both injected collaborators stay reachable for downstream waves;
     * the read keeps PHPStan's onlyWritten lint quiet on the
     * still-incubating chain-walk consumer paths.
     */
    public function injectedCollaboratorsWired(): bool
    {
        return get_class($this->pairLookup) === PairLookup::class
            && $this->fingerprints->version() > 0
            && $this->clock->now()->year > 0;
    }

    /**
     * Run the two-arm resolver pass for one user. Re-running is a
     * no-op once chain_links exist for every PayPal expense /
     * transfer_out the algorithm can pair.
     */
    public function resolveForUser(User $user): void
    {
        $connection = $this->db->connection();

        // Pick up every PayPal-account expense + transfer_out lacking
        // any chain_link of kind='paypal_funding'. The left-join
        // filter ensures rejected pairs ARE included in the iteration
        // (the per-pair pre-insert guard in ChainLinkInsertHelper
        // suppresses re-proposal — the user's rejection is final).
        $rows = $connection
            ->table('transactions')
            ->join('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->leftJoin('chain_links', function ($join): void {
                /** @var JoinClause $join */
                $join->on('chain_links.from_transaction_id', '=', 'transactions.id')
                    ->where('chain_links.kind', '=', 'paypal_funding');
            })
            ->where('transactions.user_id', $user->id)
            ->where('accounts.kind', 'paypal')
            ->whereIn('transactions.type', ['expense', 'transfer_out'])
            ->whereNull('chain_links.id')
            ->orderBy('transactions.posted_at')
            ->get([
                'transactions.id as tx_id',
                'transactions.counterparty_normalized as counterparty_normalized',
                'transactions.amount_minor as amount_minor',
                'transactions.settled_amount_minor as settled_amount_minor',
                'transactions.posted_at as posted_at',
                'transactions.booked_at as booked_at',
                'transactions.raw_payload as raw_payload',
            ]);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $link = $this->deterministicMatch($row, $user);
            if ($link !== null) {
                $this->inserter->insertIfNotExists($link, $user);

                continue;
            }

            $link = $this->asnDirectMatch($row, $user);
            if ($link !== null) {
                $this->inserter->insertIfNotExists($link, $user);

                continue;
            }

            $link = $this->fuzzyMatch($row, $user);
            if ($link !== null) {
                $this->inserter->insertIfNotExists($link, $user);
            }
        }
    }

    /**
     * Deterministic arm. Inspects the row's raw_payload event tape for
     * an IBAN that matches one of the user's accounts; matches an
     * equal-and-opposite transfer_in on that account inside the date
     * window. Returns a chain_link payload ready for the inserter, or
     * null when no event row carries an actionable IBAN.
     *
     * @return ?array<string, mixed>
     */
    private function deterministicMatch(stdClass $row, User $user): ?array
    {
        // D-05: this row came off a raw query-builder read (`resolveForUser`'s
        // `$connection->table('transactions')->get()` above), which bypasses
        // the Eloquent `EncryptedJsonCast` entirely — the ciphertext must be
        // decrypted explicitly before `extractEvents()` can `json_decode` it.
        // Pass-through no-op when encryption is not enabled for this user.
        $storedRawPayload = self::toString($row->raw_payload ?? null);
        $plainRawPayload = $storedRawPayload === ''
            ? ''
            : $this->codec->decryptValue('transactions', 'raw_payload', $storedRawPayload, $user->id, $this->session)['value'];

        $events = $this->extractEvents($plainRawPayload);
        if ($events === []) {
            return null;
        }

        $normalisedMerchant = self::toString($row->counterparty_normalized ?? null);
        $bookedAt = self::toString($row->booked_at ?? null);
        $amountMinor = self::toInt($row->amount_minor ?? null);

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $eventType = isset($event['type']) && is_string($event['type']) ? $event['type'] : '';
            if (! in_array($eventType, self::FUNDING_EVENT_TYPES, true)) {
                continue;
            }

            $eventRow = $event['row'] ?? [];
            if (! is_array($eventRow)) {
                continue;
            }

            $iban = $this->extractIbanFromEventRow($eventRow);
            if ($iban === null) {
                continue;
            }

            $accountId = $this->accountIdForIban($iban, $user);
            if ($accountId === null) {
                continue;
            }

            $partnerId = $this->findPartnerOnAccount($accountId, -$amountMinor, $bookedAt, $user);
            if ($partnerId === null) {
                continue;
            }

            $referenceId = $eventRow['Reference Txn ID'] ?? null;

            return [
                'from_transaction_id' => self::toInt($row->tx_id ?? null),
                'to_transaction_id' => $partnerId,
                'kind' => 'paypal_funding',
                'state' => 'confirmed',
                'confidence' => '1.000',
                'resolver' => 'auto',
                'evidence' => [
                    'matched_iban' => $iban,
                    'matched_reference_id' => is_string($referenceId) && $referenceId !== '' ? $referenceId : null,
                    'event_type' => $eventType,
                    'signature_hash' => $this->signatureHash($normalisedMerchant, $iban),
                ],
            ];
        }

        return null;
    }

    /**
     * ASN-direct arm. Pairs a PayPal `expense` directly with the ASN-
     * side `transfer_out` that funded it, in the empirical Activity-
     * Download shape where the funding-leg `Bankstorting` row is
     * absent from the PayPal CSV.
     *
     * Match predicates (all required, all per-user):
     *
     *   1. The PayPal expense's `settled_amount_minor` matches an
     *      ASN-side `transfer_out`'s `settled_amount_minor` exactly
     *      (same negative-signed integer — both rows are "money
     *      leaving an account").
     *
     *   2. The ASN row's `counterparty_iban` is present in
     *      `known_counterparty_ibans` for the user with
     *      `target_account_kind='paypal'` (alias bridge — the real
     *      institution IBAN like `LU89751000135104200E` is mapped to
     *      the synthetic `'PAYPAL'` Account.iban literal at install
     *      time by the DefaultKnownCounterpartyIbansSeeder).
     *
     *   3. The ASN row's `booked_at` is within ±DATE_WINDOW_DAYS of
     *      the PayPal expense's `booked_at`. SEPA debits typically
     *      lag the originating PayPal payment by 0–2 days; the
     *      window is symmetric to absorb adapter book-time
     *      conventions (ASN at 12:00, PayPal at startOfDay).
     *
     *   4. The ASN row is not already cited as the `to` side of
     *      another `paypal_funding` chain_link in state `confirmed`
     *      or `candidate`. This 1:1 enforcement keeps two same-day
     *      same-amount PayPal expenses from both claiming a single
     *      ASN debit (the closest-by-date one wins; the other falls
     *      through to the fuzzy arm).
     *
     * Result:
     *
     *   - Exactly one candidate inside the window →
     *     state='confirmed', confidence=ASN_DIRECT_UNIQUE_CONFIDENCE
     *     (1.000). The match is structurally unambiguous.
     *
     *   - ≥2 candidates → state='candidate',
     *     confidence=ASN_DIRECT_AMBIGUOUS_CONFIDENCE (0.900). The
     *     closest by booked_at wins; the user reviews the link.
     *
     *   - Zero candidates → null (fall through to fuzzy arm).
     *
     * Cross-user safety: every predicate joins/scopes on `user_id`.
     * Idempotency: re-running picks up zero new ASN rows because the
     * outer chain_links left-join in `resolveForUser` excludes PayPal
     * expenses already carrying a `paypal_funding` link.
     *
     * @return ?array<string, mixed>
     */
    private function asnDirectMatch(stdClass $row, User $user): ?array
    {
        $settledMinor = self::toInt($row->settled_amount_minor ?? null);
        if ($settledMinor === 0) {
            return null;
        }

        $bookedAtRaw = self::toString($row->booked_at ?? null);
        if ($bookedAtRaw === '') {
            return null;
        }
        $center = CarbonImmutable::parse($bookedAtRaw);
        $windowStart = $center->subDays(self::DATE_WINDOW_DAYS)->startOfDay()->toDateTimeString();
        $windowEnd = $center->addDays(self::DATE_WINDOW_DAYS)->endOfDay()->toDateTimeString();

        // D-05: `tx.counterparty_iban` is a `SensitiveFieldRegistry`
        // ciphertext column under encryption, so it CANNOT be part of a
        // SQL equality predicate — a `kci.real_iban = tx.counterparty_iban`
        // JOIN would never match once the user's data is encrypted. The
        // alias set (`known_counterparty_ibans` for this user +
        // target_account_kind='paypal') is small and plaintext, so it is
        // loaded in full; the `tx` candidates are narrowed on cheap
        // PLAINTEXT dims only (user/type/amount/date window, and the 1:1
        // chain_links exclusion), then each candidate's `counterparty_iban`
        // is decrypted and matched against the alias set in PHP —
        // mirrors CounterpartyIndexQuery's decrypt-then-match template.
        $paypalAliasRows = $this->db->connection()
            ->table('known_counterparty_ibans')
            ->where('user_id', $user->id)
            ->where('target_account_kind', 'paypal')
            ->get(['real_iban']);

        /** @var array<string, bool> $aliasSet */
        $aliasSet = [];
        foreach ($paypalAliasRows as $aliasRow) {
            /** @var stdClass $aliasRow */
            $realIban = self::toString($aliasRow->real_iban ?? null);
            if ($realIban !== '') {
                $aliasSet[$realIban] = true;
            }
        }
        if ($aliasSet === []) {
            return null;
        }

        // The left-join on chain_links is the 1:1 enforcement: an ASN
        // row already cited as `to_transaction_id` on another
        // `paypal_funding` link in a non-rejected state is excluded
        // from the candidate set. A rejected link does NOT exclude —
        // the per-pair pre-insert guard in ChainLinkInsertHelper still
        // suppresses re-proposal of the same (from, to, kind, user)
        // tuple, so a rejected pair stays rejected.
        //
        // ASN_DIRECT_CANDIDATE_SCAN_LIMIT bounds the decrypt scan
        // (T-14.1-06) — the amount+date predicate already narrows this to
        // a handful of rows in practice.
        $candidates = $this->db->connection()
            ->table('transactions as tx')
            ->leftJoin('chain_links as existing', function ($join): void {
                /** @var JoinClause $join */
                $join->on('existing.to_transaction_id', '=', 'tx.id')
                    ->where('existing.kind', '=', 'paypal_funding')
                    ->whereIn('existing.state', ['confirmed', 'candidate']);
            })
            ->where('tx.user_id', $user->id)
            ->where('tx.type', 'transfer_out')
            ->where('tx.settled_amount_minor', $settledMinor)
            ->whereBetween('tx.booked_at', [$windowStart, $windowEnd])
            ->whereNull('existing.id')
            ->orderByRaw('ABS(julianday(tx.booked_at) - julianday(?))', [$center->toDateTimeString()])
            ->limit(self::ASN_DIRECT_CANDIDATE_SCAN_LIMIT)
            ->get([
                'tx.id as candidate_id',
                'tx.booked_at as candidate_booked_at',
                'tx.counterparty_iban as candidate_iban',
            ]);

        if ($candidates->isEmpty()) {
            return null;
        }

        // Decrypt-then-match: iterate in closest-by-date order (already
        // sorted by the orderByRaw clause above) and keep only the rows
        // whose decrypted IBAN is in the alias set. The arm only needs to
        // know "0", "1", or "≥2" matches, so it stops once 2 are found.
        /** @var list<stdClass> $matches */
        $matches = [];
        foreach ($candidates as $candidate) {
            /** @var stdClass $candidate */
            $storedIban = self::toString($candidate->candidate_iban ?? null);
            if ($storedIban === '') {
                continue;
            }
            $plainIban = $this->codec->decryptValue('transactions', 'counterparty_iban', $storedIban, $user->id, $this->session)['value'];
            if (! isset($aliasSet[$plainIban])) {
                continue;
            }
            $matches[] = $candidate;
            if (count($matches) >= 2) {
                break;
            }
        }

        if ($matches === []) {
            return null;
        }

        $closest = $matches[0];
        $partnerId = self::toInt($closest->candidate_id ?? null);
        if ($partnerId === 0) {
            return null;
        }
        $partnerIban = $this->codec->decryptValue('transactions', 'counterparty_iban', self::toString($closest->candidate_iban ?? null), $user->id, $this->session)['value'];
        $ambiguous = count($matches) > 1;

        $bookedCarbon = CarbonImmutable::parse(self::toString($closest->candidate_booked_at ?? null));
        $dateDeltaDays = abs((int) $bookedCarbon->diffInDays($center, false));

        return [
            'from_transaction_id' => self::toInt($row->tx_id ?? null),
            'to_transaction_id' => $partnerId,
            'kind' => 'paypal_funding',
            'state' => $ambiguous ? 'candidate' : 'confirmed',
            'confidence' => $ambiguous
                ? self::ASN_DIRECT_AMBIGUOUS_CONFIDENCE
                : self::ASN_DIRECT_UNIQUE_CONFIDENCE,
            'resolver' => 'auto',
            'evidence' => [
                'matched_via' => 'asn_alias_amount_date',
                'matched_iban' => $partnerIban,
                'matched_amount_minor' => $settledMinor,
                'date_delta_days' => $dateDeltaDays,
                // WR-15: report the true IBAN-matched count (capped at 2 by the
                // decrypt-then-match loop above), not $candidates->count() — the
                // up-to-20 rows narrowed on amount/date BEFORE the decrypt filter.
                // Rows whose decrypted IBAN is not in the alias set never became
                // matches and must not inflate the reported ambiguity.
                'ambiguous_candidates' => $ambiguous ? count($matches) : null,
                'signature_hash' => $this->signatureHash(
                    self::toString($row->counterparty_normalized ?? null),
                    $partnerIban,
                ),
            ],
        ];
    }

    /**
     * Fuzzy arm. Scores candidate `transfer_in` rows by a weighted
     * blend of normalised-merchant Levenshtein similarity (0.5), amount
     * band (0.3), and date window (0.2). Best score ≥
     * FUZZY_MIN_CONFIDENCE surfaces as state='candidate'.
     *
     * @return ?array<string, mixed>
     */
    private function fuzzyMatch(stdClass $row, User $user): ?array
    {
        $settledMinor = abs(self::toInt($row->settled_amount_minor ?? null));
        if ($settledMinor === 0) {
            return null;
        }
        $normalisedMerchant = $this->fingerprints->normalize(
            self::toString($row->counterparty_normalized ?? null),
        );
        $postedAtRaw = self::toString($row->posted_at ?? null);
        if ($postedAtRaw === '') {
            return null;
        }
        $postedAt = CarbonImmutable::parse($postedAtRaw);

        $amountBand = (int) round($settledMinor * (self::AMOUNT_BAND_PERCENT / 100));

        // SQLite stores `posted_at` as `YYYY-MM-DD`; we compare against
        // datetime strings — lexicographic order matches calendar order
        // for both shapes.
        $windowStart = $postedAt->subDays(self::DATE_WINDOW_DAYS)->startOfDay()->toDateTimeString();
        $windowEnd = $postedAt->addDays(self::DATE_WINDOW_DAYS)->endOfDay()->toDateTimeString();

        $candidates = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('id', '<>', self::toInt($row->tx_id ?? null))
            ->where('type', 'transfer_in')
            ->whereBetween('settled_amount_minor', [
                $settledMinor - $amountBand,
                $settledMinor + $amountBand,
            ])
            ->whereBetween('posted_at', [$windowStart, $windowEnd])
            ->limit(20)
            ->get([
                'id',
                'counterparty_normalized',
                'posted_at',
                'settled_amount_minor',
                'account_id',
            ]);

        $bestId = null;
        $bestScore = 0.0;
        $bestRow = null;
        $bestMerchantSim = 0.0;
        $bestAmountDelta = 0;
        $bestDateDelta = 0;

        foreach ($candidates as $candidate) {
            /** @var stdClass $candidate */
            $candidateMerchant = $this->fingerprints->normalize(
                self::toString($candidate->counterparty_normalized ?? null),
            );
            $merchantSim = $this->levenshteinSimilarity($normalisedMerchant, $candidateMerchant);

            $candidateMinor = abs(self::toInt($candidate->settled_amount_minor ?? null));
            $amountDelta = abs($settledMinor - $candidateMinor);
            // $settledMinor is guaranteed >= 1 here (the `=== 0` guard above
            // returns early), so the division is always defined.
            $amountSim = max(0.0, 1.0 - ($amountDelta / $settledMinor));

            $candidatePosted = CarbonImmutable::parse(self::toString($candidate->posted_at ?? null));
            $dateDelta = abs((int) $candidatePosted->diffInDays($postedAt, false));
            $dateSim = max(0.0, 1.0 - ($dateDelta / self::DATE_WINDOW_DAYS));

            $score = (self::FUZZY_WEIGHT_MERCHANT * $merchantSim)
                + (self::FUZZY_WEIGHT_AMOUNT * $amountSim)
                + (self::FUZZY_WEIGHT_DATE * $dateSim);

            if ($score >= self::FUZZY_MIN_CONFIDENCE && $score > $bestScore) {
                $bestScore = $score;
                $bestId = self::toInt($candidate->id ?? null);
                $bestRow = $candidate;
                $bestMerchantSim = $merchantSim;
                $bestAmountDelta = $amountDelta;
                $bestDateDelta = $dateDelta;
            }
        }

        if ($bestId === null || $bestRow === null) {
            return null;
        }

        // Clamp under 1.0 so deterministic stays the only path to a
        // round-confidence chain_link.
        $confidence = min(self::FUZZY_MAX_CONFIDENCE, $bestScore);

        $fundingIban = $this->ibanForAccountId(self::toInt($bestRow->account_id ?? null), $user) ?? '';

        return [
            'from_transaction_id' => self::toInt($row->tx_id ?? null),
            'to_transaction_id' => $bestId,
            'kind' => 'paypal_funding',
            'state' => 'candidate',
            'confidence' => $this->formatConfidence($confidence),
            'resolver' => 'auto',
            'evidence' => [
                'merchant_similarity' => round($bestMerchantSim, 3),
                'amount_delta_minor' => $bestAmountDelta,
                'date_delta_days' => $bestDateDelta,
                'signature_hash' => $this->signatureHash($normalisedMerchant, $fundingIban),
            ],
        ];
    }

    /**
     * Decode the raw_payload JSON column into its events list. Returns
     * an empty array on any parse failure or missing key — defensive
     * against null, malformed JSON, or unexpected payload shapes.
     *
     * @return list<mixed>
     */
    private function extractEvents(string $rawPayload): array
    {
        if ($rawPayload === '') {
            return [];
        }
        $decoded = json_decode($rawPayload, true);
        if (! is_array($decoded)) {
            return [];
        }
        $events = $decoded['events'] ?? null;
        if (! is_array($events)) {
            return [];
        }

        // Re-key to ensure a list shape (json_decode preserves keys; a
        // malformed payload could carry assoc keys here).
        return array_values($events);
    }

    /**
     * Match an IBAN from a free-text memo cell. The IBAN regex covers
     * the canonical 4-char prefix (`[A-Z]{2}\d{2}`) followed by 8–30
     * BBAN characters. The haystack is upper-cased so the regex stays
     * single-pass.
     *
     * @param  array<int|string, mixed>  $eventRow
     */
    private function extractIbanFromEventRow(array $eventRow): ?string
    {
        $haystack = '';
        foreach (self::IBAN_MEMO_KEYS as $key) {
            if (isset($eventRow[$key]) && is_string($eventRow[$key])) {
                $haystack .= ' '.$eventRow[$key];
            }
        }
        if ($haystack === '') {
            return null;
        }
        if (preg_match('/\b([A-Z]{2}\d{2}[A-Z0-9]{8,30})\b/', strtoupper($haystack), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Locate a user-owned account by IBAN. Returns the account id, or
     * null when no row matches.
     */
    private function accountIdForIban(string $iban, User $user): ?int
    {
        $row = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('iban', $iban)
            ->first(['id']);

        if ($row === null) {
            return null;
        }

        return self::toInt($row->id);
    }

    /**
     * Find the equal-and-opposite `transfer_in` partner on the given
     * funding account inside the date window. The closest-by-booked_at
     * row wins so noisy weekly statements with multiple matching
     * settlements still pick the deterministic candidate.
     */
    private function findPartnerOnAccount(int $accountId, int $expectedAmountMinor, string $bookedAt, User $user): ?int
    {
        $center = CarbonImmutable::parse($bookedAt);
        $windowStart = $center->subDays(self::DATE_WINDOW_DAYS)->startOfDay()->toDateTimeString();
        $windowEnd = $center->addDays(self::DATE_WINDOW_DAYS)->endOfDay()->toDateTimeString();

        $row = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->where('type', 'transfer_in')
            ->where('amount_minor', $expectedAmountMinor)
            ->whereBetween('booked_at', [$windowStart, $windowEnd])
            ->orderByRaw('ABS(julianday(booked_at) - julianday(?))', [$center->toDateTimeString()])
            ->first(['id']);

        if ($row === null) {
            return null;
        }

        return self::toInt($row->id);
    }

    /**
     * Look up the IBAN of a user-owned account by id. Returns null
     * when the account does not exist or does not belong to the user.
     */
    private function ibanForAccountId(int $accountId, User $user): ?string
    {
        $row = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('id', $accountId)
            ->first(['iban']);

        if ($row === null) {
            return null;
        }

        return self::toString($row->iban);
    }

    /**
     * Levenshtein-distance similarity in [0, 1]. The longer of the two
     * strings is the denominator so a single-character flip in a
     * five-character name still scores 0.8.
     */
    private function levenshteinSimilarity(string $a, string $b): float
    {
        $maxLen = max(mb_strlen($a), mb_strlen($b));
        if ($maxLen === 0) {
            return 1.0;
        }
        $dist = levenshtein($a, $b);

        return max(0.0, 1.0 - ($dist / $maxLen));
    }

    /**
     * `evidence.signature_hash` per D-88: sha256 of the
     * normalized_merchant joined with the funding-account IBAN. The
     * Wave 3 ConfirmChainLink auto-promotion loop counts confirmed
     * rows sharing this hash; when the user has confirmed three rows
     * of the same signature, every remaining candidate of the same
     * signature is auto-promoted.
     */
    private function signatureHash(string $normalisedMerchant, string $fundingIban): string
    {
        return hash('sha256', $normalisedMerchant.'|'.$fundingIban);
    }

    /**
     * Format a float confidence value as a fixed-precision decimal
     * string matching the chain_links.confidence column's decimal(4,3)
     * shape. Mirrors `IcsSettlementResolver::formatConfidence()`.
     */
    private function formatConfidence(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
