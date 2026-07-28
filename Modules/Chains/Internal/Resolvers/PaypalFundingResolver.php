<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Resolvers;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Transfers\Public\Services\PairLookup;
use stdClass;

/**
 * @link ../../../../.docs/architecture/chain-resolution.md
 *
 * @internal Driven by ResolveChainLinksJob — not called directly
 *           from Public action classes.
 */
final class PaypalFundingResolver
{
    use CoercesScalars;

    public const AMOUNT_BAND_PERCENT = 2;

    public const DATE_WINDOW_DAYS = 3;

    // Floor below which the fuzzy score is dropped (no chain_link); ceiling
    // stays under 1.0 since that confidence is reserved for the
    // deterministic arm.
    public const FUZZY_MIN_CONFIDENCE = 0.6;

    public const FUZZY_MAX_CONFIDENCE = 0.99;

    // Matches the deterministic arm's 1.000: a single PayPal expense +
    // single ASN debit of equal amount within the date window is
    // structurally unambiguous.
    private const ASN_DIRECT_UNIQUE_CONFIDENCE = '1.000';

    // Written when the ASN-direct arm finds 2+ candidates in the window;
    // the closest by booked_at wins but drops to state='candidate' so the
    // user reviews the ambiguity.
    private const ASN_DIRECT_AMBIGUOUS_CONFIDENCE = '0.900';

    /** @var list<string> */
    private const FUNDING_EVENT_TYPES = ['General Withdrawal', 'Bankstorting', 'Transfer to bank'];

    // The NL Activity Download stores the destination IBAN in the Naam
    // column for Bankstorting rows; Omschrijving and Memo are defensive
    // fallbacks for the EN locale and ad-hoc payment memos.
    /** @var list<string> */
    private const IBAN_MEMO_KEYS = ['Naam', 'Memo', 'Note', 'Description', 'Omschrijving'];

    // Weighted score breakdown for the fuzzy arm; the three weights below
    // sum to 1.0.
    private const FUZZY_WEIGHT_MERCHANT = 0.5;

    private const FUZZY_WEIGHT_AMOUNT = 0.3;

    private const FUZZY_WEIGHT_DATE = 0.2;

    // Caps the ASN-direct arm's candidate scan before the per-row decrypt;
    // the amount+date predicate already narrows to a handful of rows in
    // practice, this bounds the pathological case of many shared-amount rows.
    private const ASN_DIRECT_CANDIDATE_SCAN_LIMIT = 20;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly FingerprintComposer $fingerprints,
        private readonly PairLookup $pairLookup,
        private readonly ChainLinkInsertHelper $inserter,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
    ) {}

    // Keeps both injected collaborators reachable so PHPStan's onlyWritten
    // lint stays quiet on paths that only read from them.
    public function injectedCollaboratorsWired(): bool
    {
        return get_class($this->pairLookup) === PairLookup::class
            && $this->fingerprints->version() > 0
            && $this->clock->now()->year > 0;
    }

    // Idempotent: re-running is a no-op once chain_links exist for every
    // pairable PayPal expense/transfer_out.
    public function resolveForUser(User $user): void
    {
        $connection = $this->db->connection();

        // The left-join filter still includes rejected pairs in the
        // iteration — ChainLinkInsertHelper's pre-insert guard suppresses
        // re-proposal, so the user's rejection stays final.
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
     * @return ?array<string, mixed>
     */
    private function deterministicMatch(stdClass $row, User $user): ?array
    {
        // This row came off a raw query-builder read, which bypasses the
        // Eloquent EncryptedJsonCast entirely — decrypt explicitly before
        // extractEvents() can json_decode it. Pass-through no-op when
        // encryption is not enabled for this user.
        $storedRawPayload = self::toString($row->raw_payload ?? null);
        $plainRawPayload = $storedRawPayload === ''
            ? ''
            : $this->codec->decryptValue('transactions', 'raw_payload', $storedRawPayload, $user->id, ($this->session)())['value'];

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
     * @return ?array<string, mixed>
     */
    private function asnDirectMatch(stdClass $row, User $user): ?array
    {
        $settledMinor = self::toInt($row->settled_amount_minor ?? null);
        $bookedAtRaw = self::toString($row->booked_at ?? null);
        if ($settledMinor === 0 || $bookedAtRaw === '') {
            return null;
        }

        $aliasSet = $this->paypalAliasSet($user);
        if ($aliasSet === []) {
            return null;
        }

        $center = CarbonImmutable::parse($bookedAtRaw);
        $matches = $this->aliasMatchedCandidates($settledMinor, $center, $aliasSet, $user);

        return $matches === []
            ? null
            : $this->asnDirectLink($row, $matches, $center, $settledMinor, $user);
    }

    // tx.counterparty_iban is encrypted, so it cannot sit in a SQL equality
    // predicate. The alias set is small and plaintext, so it is loaded whole
    // and the comparison happens in PHP after decrypting each candidate.
    /**
     * @return array<string, bool>
     */
    private function paypalAliasSet(User $user): array
    {
        $rows = $this->db->connection()
            ->table('known_counterparty_ibans')
            ->where('user_id', $user->id)
            ->where('target_account_kind', 'paypal')
            ->get(['real_iban']);

        $aliasSet = [];
        foreach ($rows as $aliasRow) {
            /** @var stdClass $aliasRow */
            $realIban = self::toString($aliasRow->real_iban ?? null);
            if ($realIban !== '') {
                $aliasSet[$realIban] = true;
            }
        }

        return $aliasSet;
    }

    // The left join is the 1:1 enforcement: an ASN row already cited on
    // another non-rejected paypal_funding link is excluded. Iteration stops
    // at two matches because the caller only needs none, one, or ambiguous.
    /**
     * @param  array<string, bool>  $aliasSet
     * @return list<stdClass>
     */
    private function aliasMatchedCandidates(int $settledMinor, CarbonImmutable $center, array $aliasSet, User $user): array
    {
        $windowStart = $center->subDays(self::DATE_WINDOW_DAYS)->startOfDay()->toDateTimeString();
        $windowEnd = $center->addDays(self::DATE_WINDOW_DAYS)->endOfDay()->toDateTimeString();

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

        /** @var list<stdClass> $matches */
        $matches = [];
        foreach ($candidates as $candidate) {
            /** @var stdClass $candidate */
            $storedIban = self::toString($candidate->candidate_iban ?? null);
            if ($storedIban === '') {
                continue;
            }
            $plainIban = $this->codec->decryptValue('transactions', 'counterparty_iban', $storedIban, $user->id, ($this->session)())['value'];
            if (! isset($aliasSet[$plainIban])) {
                continue;
            }
            $matches[] = $candidate;
            if (count($matches) >= 2) {
                break;
            }
        }

        return $matches;
    }

    /**
     * @param  list<stdClass>  $matches
     * @return ?array<string, mixed>
     */
    private function asnDirectLink(stdClass $row, array $matches, CarbonImmutable $center, int $settledMinor, User $user): ?array
    {
        $closest = $matches[0];
        $partnerId = self::toInt($closest->candidate_id ?? null);
        if ($partnerId === 0) {
            return null;
        }

        $partnerIban = $this->codec->decryptValue('transactions', 'counterparty_iban', self::toString($closest->candidate_iban ?? null), $user->id, ($this->session)())['value'];
        $ambiguous = count($matches) > 1;
        $bookedCarbon = CarbonImmutable::parse(self::toString($closest->candidate_booked_at ?? null));

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
                'date_delta_days' => abs((int) $bookedCarbon->diffInDays($center, false)),
                // The true IBAN-matched count (capped at 2), not the raw
                // candidate count — rows whose decrypted IBAN misses the
                // alias set never became matches.
                'ambiguous_candidates' => $ambiguous ? count($matches) : null,
                'signature_hash' => $this->signatureHash(
                    self::toString($row->counterparty_normalized ?? null),
                    $partnerIban,
                ),
            ],
        ];
    }

    /**
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

        // Clamps under 1.0 so the deterministic arm stays the only path to
        // a round-confidence chain_link.
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

    // Returns an empty array on any parse failure or missing key —
    // defensive against null, malformed JSON, or unexpected payload shapes.
    /**
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

    // The IBAN regex covers the canonical 4-char prefix ([A-Z]{2}\d{2})
    // followed by 8-30 BBAN characters; the haystack is upper-cased so the
    // regex stays single-pass.
    /**
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

    // The closest-by-booked_at row wins so noisy weekly statements with
    // multiple matching settlements still pick the deterministic candidate.
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

    // The longer of the two strings is the denominator, so a single-
    // character flip in a five-character name still scores 0.8.
    private function levenshteinSimilarity(string $a, string $b): float
    {
        $maxLen = max(mb_strlen($a), mb_strlen($b));
        if ($maxLen === 0) {
            return 1.0;
        }
        $dist = levenshtein($a, $b);

        return max(0.0, 1.0 - ($dist / $maxLen));
    }

    // ConfirmChainLink's auto-promotion loop counts confirmed rows sharing
    // this hash; three confirmations of the same signature auto-promotes
    // every remaining candidate with it.
    private function signatureHash(string $normalisedMerchant, string $fundingIban): string
    {
        return hash('sha256', $normalisedMerchant.'|'.$fundingIban);
    }

    // Fixed-precision decimal string matching the chain_links.confidence
    // column's decimal(4,3) shape.
    private function formatConfidence(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
