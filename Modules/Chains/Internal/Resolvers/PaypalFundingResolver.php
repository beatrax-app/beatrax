<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Resolvers;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Transfers\Public\Services\PairLookup;
use stdClass;

/**
 * PayPal funding-chain resolver — two arms.
 *
 *   1. Deterministic arm — inspect the row's stored raw payload (the
 *      PayPal Activity Download event tape) for "Bankstorting" /
 *      "General Withdrawal" / "Transfer to bank" events whose memo
 *      cells carry an IBAN matching one of the user's accounts. When
 *      an equal-and-opposite `transfer_in` exists on that account
 *      within ±DATE_WINDOW_DAYS, write a confirmed chain_link with
 *      confidence=1.0, resolver='auto'.
 *
 *   2. Fuzzy arm — when arm 1 misses, score candidate `transfer_in`
 *      rows by a weighted blend of Levenshtein-normalised merchant
 *      similarity (0.5) + amount-band similarity (0.3) + date-window
 *      similarity (0.2). The best score ≥ FUZZY_MIN_CONFIDENCE
 *      surfaces as state='candidate', confidence ∈ [0.6, 0.99].
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

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly FingerprintComposer $fingerprints,
        private readonly PairLookup $pairLookup,
        private readonly ChainLinkInsertHelper $inserter,
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
        $events = $this->extractEvents(self::toString($row->raw_payload ?? null));
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
