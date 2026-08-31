<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Resolvers;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Internal\ConfidenceScale;
use Modules\Chains\Internal\Enums\ChainLinkResolver;
use Modules\Chains\Internal\PaypalFundingSignatureKey;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Transfers\Public\Enums\CounterLegOrder;
use Modules\Transfers\Public\Services\PairLookup;
use Modules\Transfers\Public\Support\CounterLegMatch;
use Modules\Transfers\Public\Support\CounterLegWindow;
use stdClass;

/**
 * @link ../../../../.docs/architecture/chain-resolution.md
 *
 * @internal Driven by ResolveChainLinksJob — not called directly
 *           from Public action classes.
 */
final readonly class PaypalFundingResolver
{
    use CoercesScalars;

    public const int AMOUNT_BAND_PERCENT = 2;

    public const float FUZZY_MIN_CONFIDENCE = 0.6;

    public const float FUZZY_MAX_CONFIDENCE = 0.99;

    private const string UNIQUE_MATCH_CONFIDENCE = '1.000';

    private const string AMBIGUOUS_MATCH_CONFIDENCE = '0.900';

    // An exact amount on the day already carries 0.5 of the 0.6 threshold, so
    // without a floor of its own the merchant term decides nothing: "Netflix
    // International BV" scored 0.625 against "Nationale Nederlanden". The
    // merchant term has to clear the bar the whole score has to clear.
    private const float FUZZY_MIN_MERCHANT_SIMILARITY = 0.6;

    /** @var list<string> */
    private const array FUNDING_EVENT_TYPES = ['General Withdrawal', 'Bankstorting', 'Transfer to bank'];

    // The NL Activity Download carries the destination IBAN in the Naam
    // column; the remaining keys are EN-locale and ad-hoc memo fallbacks.
    /** @var list<string> */
    private const array IBAN_MEMO_KEYS = ['Naam', 'Memo', 'Note', 'Description', 'Omschrijving'];

    private const float FUZZY_WEIGHT_MERCHANT = 0.5;

    private const float FUZZY_WEIGHT_AMOUNT = 0.3;

    private const float FUZZY_WEIGHT_DATE = 0.2;

    private const int ASN_DIRECT_CANDIDATE_SCAN_LIMIT = 20;

    private const int FUZZY_CANDIDATE_SCAN_LIMIT = 20;

    public function __construct(
        private DatabaseManager $db,
        private FingerprintComposer $fingerprints,
        private ChainLinkInsertHelper $inserter,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private PairLookup $pairs,
        private PaypalFundingSignatureKey $signatureKey,
    ) {}

    public function resolveForUser(User $user): void
    {
        $connection = $this->db->connection();

        // A rejected link must not take its source row out of the iteration:
        // the funder the reader turned this one down for may import later.
        // ChainLinkInsertHelper's pre-insert guard tests every state, so the
        // rejected PAIR is still never proposed a second time.
        $rows = $connection
            ->table('transactions')
            ->join('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->leftJoin('chain_links', function ($join): void {
                /** @var JoinClause $join */
                $join->on('chain_links.from_transaction_id', '=', 'transactions.id')
                    ->where('chain_links.kind', '=', ChainLinkKind::PaypalFunding->value)
                    ->whereIn('chain_links.state', [ChainLinkState::Confirmed->value, ChainLinkState::Candidate->value]);
            })
            ->where('transactions.user_id', $user->id)
            ->where('accounts.kind', AccountKind::Paypal->value)
            ->whereIn('transactions.type', [TransactionType::Expense->value, TransactionType::TransferOut->value])
            ->whereNull('chain_links.id')
            ->orderBy('transactions.posted_at')
            ->get([
                'transactions.id as tx_id',
                'transactions.counterparty_normalized as counterparty_normalized',
                'transactions.counterparty_name as counterparty_name',
                'transactions.amount_minor as amount_minor',
                'transactions.currency as currency',
                'transactions.settled_amount_minor as settled_amount_minor',
                'transactions.settled_currency as settled_currency',
                'transactions.posted_at as posted_at',
                'transactions.booked_at as booked_at',
                'transactions.raw_payload as raw_payload',
            ]);

        if ($rows->isEmpty()) {
            return;
        }

        // The registered aliases belong to the reader, not to the row, so the
        // ASN-direct arm below is handed them rather than asking per payment.
        $aliasSet = $this->paypalAliasSet($user);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $link = $this->deterministicMatch($row, $user);
            if ($link !== null) {
                $this->inserter->insertIfNotExists($link, $user->id);

                continue;
            }

            $link = $this->asnDirectMatch($row, $aliasSet, $user);
            if ($link !== null) {
                $this->inserter->insertIfNotExists($link, $user->id);

                continue;
            }

            $link = $this->fuzzyMatch($row, $user);
            if ($link !== null) {
                $this->inserter->insertIfNotExists($link, $user->id);
            }
        }
    }

    /**
     * @return ?array<string, mixed>
     */
    private function deterministicMatch(stdClass $row, User $user): ?array
    {
        // The raw query-builder read bypasses Eloquent's EncryptedJsonCast, so
        // the payload must be decrypted here before json_decode can see it.
        $storedRawPayload = self::toString($row->raw_payload ?? null);
        $plainRawPayload = $storedRawPayload === ''
            ? ''
            : $this->codec->decryptValue('transactions', 'raw_payload', $storedRawPayload, $user->id, ($this->session)())['value'];

        $events = $this->extractEvents($plainRawPayload);
        if ($events === []) {
            return null;
        }

        foreach ($events as $event) {
            $link = $this->fundingEventLink($event, $row, $user);
            if ($link !== null) {
                return $link;
            }
        }

        return null;
    }

    /**
     * @return array{type: string, row: array<int|string, mixed>}|null
     */
    private function fundingEventRow(mixed $event): ?array
    {
        if (! is_array($event)) {
            return null;
        }
        $eventType = isset($event['type']) && is_string($event['type']) ? $event['type'] : '';
        $eventRow = $event['row'] ?? [];
        if (! in_array($eventType, self::FUNDING_EVENT_TYPES, true) || ! is_array($eventRow)) {
            return null;
        }

        return ['type' => $eventType, 'row' => $eventRow];
    }

    /**
     * @return ?array<string, mixed>
     */
    private function fundingEventLink(mixed $event, stdClass $row, User $user): ?array
    {
        $parsed = $this->fundingEventRow($event);
        if ($parsed === null) {
            return null;
        }

        $iban = $this->extractIbanFromEventRow($parsed['row']);
        $accountId = $iban === null ? null : $this->signatureKey->accountIdForIban($iban, $user);
        if ($accountId === null || $iban === null) {
            return null;
        }

        // This arm links rows the transfer matcher never pairs, so an existing
        // pair does not disqualify one. The currency does: the amounts compared
        // are both native `amount_minor`, and without it USD 50.00 matched
        // EUR 50.00 and was written confirmed at 1.000.
        $amountMinor = self::toInt($row->amount_minor ?? null);
        $currency = self::toString($row->currency ?? null);
        $centre = CarbonImmutable::parse(self::toString($row->booked_at ?? null));

        $partnerId = $this->counterLegFor($accountId, -$amountMinor, $currency, $centre, null, $user);
        if ($partnerId === null) {
            return null;
        }

        // The PayPal export names the destination ACCOUNT, never the row on it.
        // A second incoming row of the same size in the window is a second
        // answer to the same question — a webshop refund took the place of the
        // real funder and, written confirmed, never reached the review queue.
        $ambiguous = $this->counterLegFor($accountId, -$amountMinor, $currency, $centre, $partnerId, $user) !== null;

        $referenceId = $parsed['row']['Reference Txn ID'] ?? null;

        return [
            'from_transaction_id' => self::toInt($row->tx_id ?? null),
            'to_transaction_id' => $partnerId,
            'kind' => ChainLinkKind::PaypalFunding->value,
            'state' => $ambiguous ? ChainLinkState::Candidate->value : ChainLinkState::Confirmed->value,
            'confidence' => $ambiguous
                ? self::AMBIGUOUS_MATCH_CONFIDENCE
                : self::UNIQUE_MATCH_CONFIDENCE,
            'resolver' => ChainLinkResolver::Auto->value,
            'evidence' => [
                'matched_iban' => $iban,
                'matched_reference_id' => is_string($referenceId) && $referenceId !== '' ? $referenceId : null,
                'event_type' => $parsed['type'],
                'ambiguous_counter_leg' => $ambiguous,
                'signature_hash' => $this->signatureHash(
                    self::toString($row->counterparty_normalized ?? null),
                    $iban,
                ),
            ],
        ];
    }

    // The one counter-leg search, asked through the service Transfers owns
    // rather than re-implemented here.
    private function counterLegFor(int $accountId, int $amountMinor, string $currency, CarbonImmutable $centre, ?int $exclude, User $user): ?int
    {
        return $this->pairs->counterLegOnAccount(
            new CounterLegMatch(
                accountId: $accountId,
                amountMinor: $amountMinor,
                types: [TransactionType::TransferIn],
                currency: $currency === '' ? null : $currency,
                unpairedOnly: false,
                excludeTransactionId: $exclude,
            ),
            new CounterLegWindow(
                $centre,
                CounterLegWindow::DEFAULT_DAYS,
                CounterLegOrder::NearestToCentre,
            ),
            $user,
        );
    }

    /**
     * @param  array<string, bool>  $aliasSet
     * @return ?array<string, mixed>
     */
    private function asnDirectMatch(stdClass $row, array $aliasSet, User $user): ?array
    {
        $settledMinor = self::toInt($row->settled_amount_minor ?? null);
        $bookedAtRaw = self::toString($row->booked_at ?? null);
        $settledCurrency = self::toString($row->settled_currency ?? null);
        if ($settledMinor === 0 || $bookedAtRaw === '' || $settledCurrency === '' || $aliasSet === []) {
            return null;
        }

        $center = CarbonImmutable::parse($bookedAtRaw);
        $matches = $this->aliasMatchedCandidates($settledMinor, $settledCurrency, $center, $aliasSet, $user);

        return $matches === []
            ? null
            : $this->asnDirectLink($row, $matches, $center, $settledMinor, $user);
    }

    /**
     * @return array<string, bool>
     */
    private function paypalAliasSet(User $user): array
    {
        $rows = $this->db->connection()
            ->table('known_counterparty_ibans')
            ->where('user_id', $user->id)
            ->where('target_account_kind', AccountKind::Paypal->value)
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

    /**
     * @param  array<string, bool>  $aliasSet
     * @return list<stdClass>
     */
    private function aliasMatchedCandidates(int $settledMinor, string $settledCurrency, CarbonImmutable $center, array $aliasSet, User $user): array
    {
        $windowStart = $center->subDays(CounterLegWindow::DEFAULT_DAYS)->startOfDay()->toDateTimeString();
        $windowEnd = $center->addDays(CounterLegWindow::DEFAULT_DAYS)->endOfDay()->toDateTimeString();

        $candidates = $this->db->connection()
            ->table('transactions as tx')
            ->leftJoin('chain_links as existing', function ($join): void {
                /** @var JoinClause $join */
                $join->on('existing.to_transaction_id', '=', 'tx.id')
                    ->where('existing.kind', '=', ChainLinkKind::PaypalFunding->value)
                    ->whereIn('existing.state', [ChainLinkState::Confirmed->value, ChainLinkState::Candidate->value]);
            })
            ->where('tx.user_id', $user->id)
            ->where('tx.type', TransactionType::TransferOut->value)
            ->where('tx.settled_amount_minor', $settledMinor)
            // The predicate the deterministic arm already carries, for the same
            // reason: these are bare minor units, so USD 13.99 answered
            // EUR 13.99 and was written confirmed at 1.000.
            ->where('tx.settled_currency', $settledCurrency)
            ->whereBetween('tx.booked_at', [$windowStart, $windowEnd])
            ->whereNull('existing.id')
            // Two legs can sit the same distance either side of the expense —
            // a morning transfer and one the evening before. Distance alone
            // leaves which of them is chosen to SQLite, so the row this arm
            // links, and the one a re-run links, need not be the same row.
            ->orderByRaw('ABS(julianday(tx.booked_at) - julianday(?)), tx.id', [$center->toDateTimeString()])
            ->limit(self::ASN_DIRECT_CANDIDATE_SCAN_LIMIT)
            ->get([
                'tx.id as candidate_id',
                'tx.account_id as candidate_account_id',
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

        $ambiguous = count($matches) > 1;
        $bookedCarbon = CarbonImmutable::parse(self::toString($closest->candidate_booked_at ?? null));
        $fundingKey = $this->signatureKey->forAccount(self::toInt($closest->candidate_account_id ?? null), $user);

        return [
            'from_transaction_id' => self::toInt($row->tx_id ?? null),
            'to_transaction_id' => $partnerId,
            'kind' => ChainLinkKind::PaypalFunding->value,
            'state' => $ambiguous ? ChainLinkState::Candidate->value : ChainLinkState::Confirmed->value,
            'confidence' => $ambiguous
                ? self::AMBIGUOUS_MATCH_CONFIDENCE
                : self::UNIQUE_MATCH_CONFIDENCE,
            'resolver' => ChainLinkResolver::Auto->value,
            // The IBAN this arm MATCHED on is a counterparty's and `evidence` is
            // a plaintext column, so it stays out. Named and hashed here is the
            // funding ACCOUNT, as in the other two arms: hashing the counterparty
            // gave one merchant on one account two signatures that never met.
            'evidence' => [
                'matched_via' => 'asn_alias_amount_date',
                'matched_iban' => $fundingKey,
                'matched_amount_minor' => $settledMinor,
                'date_delta_days' => abs((int) $bookedCarbon->diffInDays($center, false)),
                // Capped at 2 by the scan loop: this is the IBAN-matched count,
                // not the number of ambiguous candidates in the window.
                'ambiguous_candidates' => $ambiguous ? count($matches) : null,
                'signature_hash' => $this->signatureHash(
                    self::toString($row->counterparty_normalized ?? null),
                    $fundingKey,
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
        $postedAtRaw = self::toString($row->posted_at ?? null);
        $settledCurrency = self::toString($row->settled_currency ?? null);
        if ($settledMinor === 0 || $postedAtRaw === '' || $settledCurrency === '') {
            return null;
        }

        // Two values on purpose: a keyed digest of two spellings of one
        // merchant is as far apart as one of two unrelated merchants, so the
        // similarity needs the readable name — while the signature hash keeps
        // the stored key, which is what earlier evidence was hashed from.
        $storedMerchantKey = self::toString($row->counterparty_normalized ?? null);
        $readableMerchant = $this->readableMerchant($row, $user);
        if ($readableMerchant === null) {
            // Half the fuzzy score is the merchant term. Without a readable
            // name there is no merchant comparison to make, and scoring the
            // absence would assert a funding relationship on an amount and a
            // date alone.
            return null;
        }

        $best = $this->bestFundingCandidate(
            $row,
            $user,
            $readableMerchant,
            $settledMinor,
            $settledCurrency,
            CarbonImmutable::parse($postedAtRaw),
        );

        return $best === null ? null : $this->fuzzyLink($row, $best, $storedMerchantKey, $user);
    }

    /**
     * @return array{transactionId: int, accountId: int, score: float, merchantSimilarity: float, amountDeltaMinor: int, dateDeltaDays: int}|null
     */
    private function bestFundingCandidate(stdClass $row, User $user, string $readableMerchant, int $settledMinor, string $settledCurrency, CarbonImmutable $postedAt): ?array
    {
        $amountBand = (int) round($settledMinor * (self::AMOUNT_BAND_PERCENT / 100));

        // `posted_at` is a DATE column, so both bounds are dates too: as
        // strings '2026-04-14' < '2026-04-14 00:00:00', which cut the window
        // to [-2, +3] days while the constant says three either way.
        // IcsSettlementResolver::periodDay() carries the same rule.
        $windowStart = $postedAt->subDays(CounterLegWindow::DEFAULT_DAYS)->toDateString();
        $windowEnd = $postedAt->addDays(CounterLegWindow::DEFAULT_DAYS)->toDateString();

        $candidates = $this->db->connection()->table('transactions')
            // The exclusion the ASN-direct arm carries: without it two PayPal
            // expenses claimed the same funding leg, putting one transaction in
            // two chains.
            ->leftJoin('chain_links as existing', function ($join): void {
                /** @var JoinClause $join */
                $join->on('existing.to_transaction_id', '=', 'transactions.id')
                    ->where('existing.kind', '=', ChainLinkKind::PaypalFunding->value)
                    ->whereIn('existing.state', [ChainLinkState::Confirmed->value, ChainLinkState::Candidate->value]);
            })
            ->whereNull('existing.id')
            ->where('transactions.user_id', $user->id)
            ->where('transactions.id', '<>', self::toInt($row->tx_id ?? null))
            ->where('transactions.type', TransactionType::TransferIn->value)
            ->whereBetween('transactions.settled_amount_minor', [
                $settledMinor - $amountBand,
                $settledMinor + $amountBand,
            ])
            // The amount band is bare minor units, so without this the band is
            // arithmetic on unlike quantities and the 2% window straddles two
            // currencies at once.
            ->where('transactions.settled_currency', $settledCurrency)
            ->whereBetween('transactions.posted_at', [$windowStart, $windowEnd])
            // The same rule the ASN arm carries, and for a stronger reason: an
            // unordered cut decides WHICH rows get scored at all, so a better
            // match beyond it was never compared. Nearest first also means the
            // rows this keeps are the ones the date term would have favoured.
            ->orderByRaw('ABS(julianday(transactions.posted_at) - julianday(?)), transactions.id', [$postedAt->toDateTimeString()])
            ->limit(self::FUZZY_CANDIDATE_SCAN_LIMIT)
            ->get([
                'transactions.id',
                'transactions.counterparty_normalized',
                'transactions.counterparty_name',
                'transactions.posted_at',
                'transactions.settled_amount_minor',
                'transactions.account_id',
            ]);

        $best = null;
        $bestScore = 0.0;

        foreach ($candidates as $candidate) {
            /** @var stdClass $candidate */
            $candidateMerchant = $this->readableMerchant($candidate, $user);
            if ($candidateMerchant === null) {
                continue;
            }

            $merchantSim = $this->levenshteinSimilarity($readableMerchant, $candidateMerchant);
            if ($merchantSim < self::FUZZY_MIN_MERCHANT_SIMILARITY) {
                continue;
            }

            $candidateMinor = abs(self::toInt($candidate->settled_amount_minor ?? null));
            $amountDelta = abs($settledMinor - $candidateMinor);
            $amountSim = max(0.0, 1.0 - ($amountDelta / $settledMinor));

            $candidatePosted = CarbonImmutable::parse(self::toString($candidate->posted_at ?? null));
            $dateDelta = abs((int) $candidatePosted->diffInDays($postedAt, false));
            $dateSim = max(0.0, 1.0 - ($dateDelta / CounterLegWindow::DEFAULT_DAYS));

            $score = (self::FUZZY_WEIGHT_MERCHANT * $merchantSim)
                + (self::FUZZY_WEIGHT_AMOUNT * $amountSim)
                + (self::FUZZY_WEIGHT_DATE * $dateSim);

            if ($score >= self::FUZZY_MIN_CONFIDENCE && $score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'transactionId' => self::toInt($candidate->id ?? null),
                    'accountId' => self::toInt($candidate->account_id ?? null),
                    'score' => $score,
                    'merchantSimilarity' => $merchantSim,
                    'amountDeltaMinor' => $amountDelta,
                    'dateDeltaDays' => $dateDelta,
                ];
            }
        }

        return $best;
    }

    /**
     * @param  array{transactionId: int, accountId: int, score: float, merchantSimilarity: float, amountDeltaMinor: int, dateDeltaDays: int}  $best
     * @return array<string, mixed>
     */
    private function fuzzyLink(stdClass $row, array $best, string $storedMerchantKey, User $user): array
    {
        $fundingKey = $this->signatureKey->forAccount($best['accountId'], $user);

        return [
            'from_transaction_id' => self::toInt($row->tx_id ?? null),
            'to_transaction_id' => $best['transactionId'],
            'kind' => ChainLinkKind::PaypalFunding->value,
            'state' => ChainLinkState::Candidate->value,
            'confidence' => ConfidenceScale::format(min(self::FUZZY_MAX_CONFIDENCE, $best['score'])),
            'resolver' => ChainLinkResolver::Auto->value,
            'evidence' => [
                'matched_iban' => $fundingKey,
                'merchant_similarity' => round($best['merchantSimilarity'], 3),
                'amount_delta_minor' => $best['amountDeltaMinor'],
                'date_delta_days' => $best['dateDeltaDays'],
                'signature_hash' => $this->signatureHash($storedMerchantKey, $fundingKey),
            ],
        ];
    }

    /**
     * @return list<mixed>
     */
    private function extractEvents(string $rawPayload): array
    {
        $decoded = $rawPayload === '' ? null : json_decode($rawPayload, true);
        $events = is_array($decoded) ? ($decoded['events'] ?? null) : null;
        if (! is_array($events)) {
            return [];
        }

        return array_values($events);
    }

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

    // The bank's own spelling, normalised, so the similarity compares names
    // rather than digests. NULL — never '' — when the row carries no name or
    // this process holds no key for it: an empty string is a value, and
    // levenshteinSimilarity('', '') is a perfect 1.0 rather than no answer.
    private function readableMerchant(stdClass $row, User $user): ?string
    {
        $stored = self::toString($row->counterparty_name ?? null);
        if ($stored === '') {
            return null;
        }

        $plain = $this->codec->decryptValue(
            'transactions',
            'counterparty_name',
            $stored,
            $user->id,
            ($this->session)(),
        )['value'];

        $normalised = $this->fingerprints->normalize($plain);

        return $normalised === '' ? null : $normalised;
    }

    private function levenshteinSimilarity(string $a, string $b): float
    {
        $maxLen = max(mb_strlen($a), mb_strlen($b));
        if ($maxLen === 0) {
            return 1.0;
        }
        $dist = levenshtein($a, $b);

        return max(0.0, 1.0 - ($dist / $maxLen));
    }

    // ConfirmChainLink auto-promotes every remaining candidate once three
    // confirmed links share this hash.
    private function signatureHash(string $normalisedMerchant, string $fundingIban): string
    {
        return hash('sha256', $normalisedMerchant.'|'.$fundingIban);
    }
}
