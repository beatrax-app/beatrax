<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Adapters\EnableBanking;

use Brick\Math\Exception\MathException;
use Brick\Money\Exception\MoneyException;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Generator;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\OpenBanking\Public\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Public\Dto\FetchWindow;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The live reference `RemoteSourceAdapter` implementation for Enable
 * Banking (Req 1/5/12) — the remote-fetch analog of
 * `Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter`.
 *
 * This is the load-bearing dedup contract (D-03/Req 5): every mapped
 * field below is chosen so the fingerprint tuple `FingerprintComposer`
 * derives from the yielded `SourceTransactionDto` collides, byte for
 * byte, with the SAME real-world transaction imported from an ASN
 * CAMT.053 export. Getting one field wrong (date, sign, currency)
 * silently breaks "zero net-new duplicates" with no error — see
 * `Camt053Adapter::buildDto()` for the field choices mirrored here:
 *
 *  - `bookedAt` AND `postedAt` are BOTH `booking_date`, zeroed to
 *    midnight (NEVER `value_date`) — exactly the CAMT adapter's
 *    `CarbonImmutable::instance($booking)->startOfDay()` called twice.
 *  - `amountMinor` is derived via `Brick\Money\Money` (never `(float)`)
 *    and explicitly negated when `credit_debit_indicator === 'DBIT'`,
 *    mirroring the CAMT adapter's explicit sign application.
 *  - Counterparty name/IBAN follow the identical DBIT->creditor /
 *    CRDT->debtor direction rule `Camt053Adapter::extractCounterparty()`
 *    applies. The raw name is carried on the DTO's `counterpartyName`
 *    field unmodified — `FingerprintComposer::normalize()` is called
 *    exactly ONCE for every adapter's rows, downstream in
 *    `Modules\Import\Public\Pipeline\NormalizeStage::run()`. Neither
 *    this adapter nor `Camt053Adapter` calls `normalize()` directly;
 *    writing a second normalizer here would risk producing a
 *    subtly-different string for the identical raw name.
 *
 * Booked-only filter (T-19-08-03): any row whose `status !== 'BOOK'`
 * (e.g. PSD2 `'PDNG'` pending rows) is dropped before it ever reaches
 * `SourceTransactionDto` — the project has no concept of a pending
 * transaction anywhere in `CanonicalTransaction`.
 *
 * Institution-parameterized (Req 12): `fetch()`'s `$institutionId`
 * parameter is threaded straight through to
 * `EnableBankingHttpClient::accountDetails()`/`transactions()` as the
 * Enable Banking account `uid` — no bank name is ever hardcoded, so the
 * SAME adapter instance proves both ASN and SNS.
 *
 * Deliberately does NOT fetch `/balances`: `RemoteSourceAdapter` has no
 * channel for point-in-time balance data (see that contract's docblock —
 * a balance reading is not a statement-level opening/closing pair), so
 * balance ingestion is out of this adapter's scope entirely.
 */
final class EnableBankingSourceAdapter implements RemoteSourceAdapter
{
    public function __construct(
        private readonly EnableBankingHttpClient $client,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    public function format(): string
    {
        return 'enable-banking';
    }

    /**
     * @return Generator<int, SourceTransactionDto>
     */
    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        $ownIban = $this->resolveOwnIban($institutionId);

        $rowIndex = 0;
        $continuationKey = null;

        do {
            $response = $this->client->transactions($institutionId, $window, $continuationKey);
            $rows = EnableBankingTransactionData::collectionFromArray($this->transactionRows($response));

            foreach ($rows as $row) {
                if (! $row->isBooked()) {
                    continue;
                }

                $dto = $this->buildDto($row, $ownIban, $rowIndex);
                if ($dto === null) {
                    // WR-03: a single malformed-money booked row is skipped
                    // at row level (logged in buildDto) rather than aborting
                    // the whole generator-driven fetch mid-import.
                    continue;
                }

                yield $dto;
                $rowIndex++;
            }

            $continuationKey = $this->continuationKeyFrom($response);
        } while ($continuationKey !== null);
    }

    private function resolveOwnIban(string $institutionId): string
    {
        $details = $this->client->accountDetails($institutionId);

        $accountId = $details['account_id'] ?? null;
        if (is_array($accountId)) {
            $iban = $accountId['iban'] ?? null;
            if (is_string($iban) && $iban !== '') {
                return $iban;
            }
        }

        $topLevelIban = $details['iban'] ?? null;
        if (is_string($topLevelIban) && $topLevelIban !== '') {
            return $topLevelIban;
        }

        throw new RuntimeException(
            'EnableBankingSourceAdapter: could not resolve the own-account IBAN from the accountDetails() response.'
        );
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function transactionRows(array $response): array
    {
        $raw = $response['transactions'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function continuationKeyFrom(array $response): ?string
    {
        $key = $response['continuation_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function buildDto(EnableBankingTransactionData $row, string $ownIban, int $rowIndex): ?SourceTransactionDto
    {
        $currency = strtoupper($row->currency);
        $isDebit = $row->creditDebitIndicator === 'DBIT';

        // WR-03: validate money before touching Money::of(). An empty/
        // unknown currency (UnknownCurrencyException) or a non-numeric /
        // over-precise amount (Math\*Exception) must skip THIS row, not
        // abort the entire fetch generator. Exact-money semantics are
        // preserved for valid rows (still derived via Brick\Money, never
        // via (float)).
        try {
            $amountMinor = Money::of($row->amount, $currency)->getMinorAmount()->toInt();
        } catch (MoneyException|MathException $e) {
            $this->logger->warning(
                'EnableBankingSourceAdapter: skipping booked row with malformed money.',
                ['currency' => $currency, 'reason' => $e->getMessage()],
            );

            return null;
        }

        if ($isDebit && $amountMinor > 0) {
            $amountMinor = -$amountMinor;
        }

        $counterpartyName = $isDebit ? $row->creditorName : $row->debtorName;
        $counterpartyIban = $isDebit ? $row->creditorIban : $row->debtorIban;

        // booking_date drives BOTH bookedAt and postedAt, zeroed to
        // midnight — the single most important substitution in the
        // whole field table (RESEARCH.md Pattern 2). value_date is
        // carried on valueDate only, which is outside the fingerprint
        // hash tuple.
        $bookedAt = $this->parseRequiredDate($row->bookingDate, 'booking_date');

        // Mirrors Camt053Adapter's `$entry->getValueDate() ?? $booking`
        // fallback: an EB row missing value_date reuses the booking date
        // rather than silently falling through to CarbonImmutable::parse('')
        // (which resolves to the WALL CLOCK, not a date derived from the
        // response — see parseRequiredDate()'s docblock for why that is
        // unacceptable; valueDate itself is outside the fingerprint hash
        // tuple, but a wall-clock value here would still be a silent
        // data-integrity defect on the DTO).
        $valueDateRaw = $row->valueDate !== '' ? $row->valueDate : $row->bookingDate;

        return new SourceTransactionDto(
            bookedAt: $bookedAt,
            postedAt: $bookedAt,
            valueDate: $this->parseRequiredDate($valueDateRaw, 'value_date'),
            ownIban: $ownIban,
            counterpartyIban: $counterpartyIban,
            counterpartyName: $counterpartyName,
            currency: $currency,
            amountMinor: $amountMinor,
            sourceRef: null,
            description: $this->collapseRemittance($row->remittanceInformation),
            rawPayload: $this->serialiseEnableBankingFragment($row),
            sourceRowIndex: $rowIndex,
        );
    }

    /**
     * `CarbonImmutable::parse('')` does NOT throw — it silently resolves
     * to the WALL CLOCK ("now"), not a date derived from the aggregator's
     * response. `EnableBankingTransactionData::fromArray()` defaults a
     * missing/non-string `booking_date`/`value_date` to `''`
     * (`stringOrEmpty()`), so without this guard a malformed or
     * incomplete EB response would silently derive the fingerprinted
     * `bookedAt`/`postedAt` fields from the moment the fetch happened to
     * run — exactly the failure mode `Camt053Adapter::buildDto()`
     * explicitly rejects (`InvalidAmountException` when both `BookgDt`
     * and `ValDt` are absent) rather than allow. Getting this wrong here
     * is silent and would break "zero net-new duplicates" on any re-fetch
     * of the same window, since two fetches of an incomplete row would
     * each stamp a different wall-clock date and never re-collide.
     */
    private function parseRequiredDate(string $raw, string $fieldName): CarbonImmutable
    {
        if ($raw === '') {
            throw new RuntimeException(
                "EnableBankingSourceAdapter: transaction row is missing {$fieldName}; refusing to derive a fingerprinted date from the wall clock."
            );
        }

        return CarbonImmutable::parse($raw)->startOfDay();
    }

    /**
     * @param  list<string>  $remittanceInformation
     */
    private function collapseRemittance(array $remittanceInformation): ?string
    {
        $joined = implode(' ', $remittanceInformation);
        $collapsed = preg_replace('/\s+/u', ' ', $joined);
        $trimmed = trim(is_string($collapsed) ? $collapsed : $joined);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Mirrors `Camt053Adapter::serialiseSepaFragment()`'s
     * `rawPayload['sepa']` pattern — every secondary reference the
     * aggregator carries is stashed verbatim here rather than promoted
     * to `sourceRef`.
     *
     * @return array{enable_banking: array<string, mixed>}
     */
    private function serialiseEnableBankingFragment(EnableBankingTransactionData $row): array
    {
        return [
            'enable_banking' => [
                'transactionId' => $row->transactionId,
                'entryReference' => $row->entryReference,
                'status' => $row->status,
                'bankTransactionCode' => [
                    'domain' => $row->bankTransactionDomain,
                    'family' => $row->bankTransactionFamily,
                    'subFamily' => $row->bankTransactionSubFamily,
                ],
            ],
        ];
    }
}
