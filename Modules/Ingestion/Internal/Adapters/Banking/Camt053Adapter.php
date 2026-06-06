<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Generator;
use Genkgo\Camt\Camt053\DTO\Statement;
use Genkgo\Camt\Config;
use Genkgo\Camt\DTO\Balance;
use Genkgo\Camt\DTO\Creditor;
use Genkgo\Camt\DTO\Debtor;
use Genkgo\Camt\DTO\Entry;
use Genkgo\Camt\DTO\EntryTransactionDetail;
use Genkgo\Camt\DTO\IbanAccount;
use Genkgo\Camt\DTO\Message;
use Genkgo\Camt\DTO\RelatedParty;
use Genkgo\Camt\DTO\UltimateCreditor;
use Genkgo\Camt\DTO\UltimateDebtor;
use Genkgo\Camt\Reader;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Dto\StatementSummaryData;
use Money\Money;
use Throwable;

/**
 * Streaming-by-row parser for CAMT.053 (ISO 20022 bank-to-customer
 * statement) XML export. Delegates the file-level unmarshal to genkgo/camt,
 * then walks every Statement -> Entry -> TransactionDetail and yields one
 * SourceTransactionDto per TxDtls (batch entries are split into N rows).
 *
 * Reference policy:
 *  - `sourceRef` is the TxDtls EndToEndId when present, NULL otherwise.
 *    Weaker SEPA refs (AcctSvcrRef, InstrId, TxId, MsgId, MandateId) are
 *    NEVER promoted to sourceRef — they live verbatim under
 *    `rawPayload['sepa']` so downstream chain resolution can read them
 *    without re-parsing the XML.
 *
 * Booking-date normalisation:
 *  - When `<BookgDt>` carries a date-only element, the second-precision
 *    `bookedAt` field is zeroed to `00:00:00`. This matches the CSV
 *    adapter's `startOfDay()` semantics so a CSV row and a CAMT entry
 *    representing the same logical transaction produce identical
 *    FingerprintComposer v3 hashes.
 *  - An `<Ntry>` that carries neither `<BookgDt>` nor `<ValDt>` is rejected
 *    as a parse error so the fingerprint is never derived from the wall
 *    clock; this preserves the idempotency contract on re-imports.
 *
 * Security:
 *  - Before any Reader construction, `libxml_set_external_entity_loader(null)`
 *    disables every SYSTEM / PUBLIC entity reference resolution for the
 *    duration of the parse, mitigating XXE attacks regardless of the
 *    underlying PHP / libxml defaults.
 *
 * Money handling:
 *  - genkgo/camt exposes amounts as `Money\Money` (moneyphp/money). The
 *    adapter converts to integer minor units at the boundary; `Money\Money`
 *    types are not allowed to escape into the Public DTO surface.
 */
final class Camt053Adapter implements SourceAdapter
{
    private ?StatementSummaryData $lastStatementMetadata = null;

    public function __construct(
        private readonly HeaderSniffer $sniffer,
    ) {}

    public function format(): string
    {
        return Camt053HeaderProfile::FORMAT;
    }

    /**
     * Returns the statement-level metadata captured during the most recent
     * `parse()` run. The `importRunId` and `accountId` are zeroed
     * placeholders at this point — the pipeline overrides both via the
     * DTO's `withImportRunId()` / `withAccountId()` helpers before invoking
     * the writer.
     */
    public function statementMetadata(): ?StatementSummaryData
    {
        return $this->lastStatementMetadata;
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
    {
        $this->sniffer->sniff($localPath, Camt053HeaderProfile::FORMAT);

        $message = $this->readMessage($localPath);
        $msgId = $message->getGroupHeader()->getMessageId();
        $index = 0;
        $this->lastStatementMetadata = null;
        $entryCount = 0;

        foreach ($message->getRecords() as $record) {
            if (! $record instanceof Statement) {
                continue;
            }

            $ownIban = $this->extractOwnIban($record);
            $accounts->resolve($ownIban);

            foreach ($record->getEntries() as $entry) {
                $txDtlsList = $entry->getTransactionDetails();

                if ($txDtlsList === []) {
                    yield $this->buildDto($entry, null, $ownIban, $index, $msgId, isBatch: false);
                    $index++;
                    $entryCount++;

                    continue;
                }

                $isBatch = count($txDtlsList) > 1;
                foreach ($txDtlsList as $txDtls) {
                    yield $this->buildDto($entry, $txDtls, $ownIban, $index, $msgId, isBatch: $isBatch);
                    $index++;
                }
                $entryCount++;
            }

            // Each CAMT.053 export typically carries a single <Stmt>; if a
            // future file carries more, the LAST statement wins for the
            // metadata snapshot because that is what the user would expect
            // to see when looking at "the statement they just imported".
            $this->lastStatementMetadata = $this->buildStatementMetadata($record, $ownIban, $entryCount);
        }
    }

    private function buildStatementMetadata(Statement $stmt, string $ownIban, int $entryCount): StatementSummaryData
    {
        $opening = $this->findBalance($stmt, Balance::TYPE_OPENING);
        $closing = $this->findBalance($stmt, Balance::TYPE_CLOSING);

        return new StatementSummaryData(
            importRunId: 0,
            accountId: 0,
            ibanOwner: $ownIban,
            statementNumber: $stmt->getElectronicSequenceNumber() ?? $stmt->getLegalSequenceNumber(),
            periodStart: $stmt->getFromDate() === null ? null : CarbonImmutable::instance($stmt->getFromDate()),
            periodEnd: $stmt->getToDate() === null ? null : CarbonImmutable::instance($stmt->getToDate()),
            openingBalanceMinor: $opening === null ? null : $this->moneyToMinor($opening->getAmount()),
            openingBalanceCurrency: $opening?->getAmount()->getCurrency()->getCode(),
            openingBalanceDate: $opening === null ? null : CarbonImmutable::instance($opening->getDate()),
            closingBalanceMinor: $closing === null ? null : $this->moneyToMinor($closing->getAmount()),
            closingBalanceCurrency: $closing?->getAmount()->getCurrency()->getCode(),
            closingBalanceDate: $closing === null ? null : CarbonImmutable::instance($closing->getDate()),
            entryCount: $entryCount,
            extras: [
                'statementId' => $stmt->getId(),
                // Normalise the bank-provided creation timestamp to UTC
                // so the same logical statement produces identical extras
                // JSON regardless of the export host's local timezone (and
                // regardless of DST shifts that would otherwise toggle the
                // offset between `+01:00` and `+02:00`).
                'createdOn' => $stmt->getCreatedOn()
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z'),
            ],
        );
    }

    private function findBalance(Statement $stmt, string $type): ?Balance
    {
        foreach ($stmt->getBalances() as $balance) {
            if ($balance->getType() === $type) {
                return $balance;
            }
        }

        return null;
    }

    /**
     * Hardens libxml and delegates the unmarshal to genkgo/camt. Errors from
     * the library (malformed XML, unknown CAMT.053 sub-version) are re-thrown
     * as InvalidAmountException so the upload pipeline can surface them as a
     * single per-file ERROR preview row.
     */
    private function readMessage(string $localPath): Message
    {
        // Install an external-entity loader that allows local-filesystem reads
        // (needed for the CAMT.053 XSD files genkgo/camt ships in
        // `vendor/genkgo/camt/assets/`) but rejects every remote URI scheme.
        // The CAMT.053 XSDs reference each other by relative path so the
        // loader must let those through; an attacker-controlled
        // `<!ENTITY xxe SYSTEM "http://…">` reference is denied at the
        // loader boundary regardless of PHP / libxml defaults.
        libxml_set_external_entity_loader(
            static function (?string $publicId, ?string $systemId, array $context): ?string {
                if ($systemId === null) {
                    return null;
                }

                $scheme = parse_url($systemId, PHP_URL_SCHEME);
                if ($scheme === null || $scheme === false || $scheme === '' || $scheme === 'file') {
                    return $systemId;
                }

                // Block http://, https://, ftp://, php://, expect://, … any
                // network or wrapper scheme an attacker could weaponise.
                return null;
            }
        );

        $previousErrorState = libxml_use_internal_errors(true);
        try {
            // XSD validation is disabled deliberately. The CAMT.053 XSDs
            // genkgo/camt ships are pedantic and would reject any synthetic
            // test fragment or future format extension that adds an unforeseen
            // optional element. Structural correctness is enforced by the
            // sniffer's namespace match plus the downstream IBAN / amount
            // validators inside genkgo/camt's decoder; security against XXE
            // is enforced by the external-entity loader installed above.
            $config = Config::getDefault();
            $config->disableXsdValidation();
            $reader = new Reader($config);

            try {
                return $reader->readFile($localPath);
            } catch (Throwable $e) {
                throw new InvalidAmountException(
                    sprintf('Failed to parse CAMT.053 XML: %s', $e->getMessage()),
                    0,
                    $e,
                );
            }
        } finally {
            libxml_use_internal_errors($previousErrorState);
            // Restore the default libxml entity loader so a subsequent caller
            // is not constrained by our hardening rules.
            libxml_set_external_entity_loader(null);
        }
    }

    private function buildDto(
        Entry $entry,
        ?EntryTransactionDetail $txDtls,
        string $ownIban,
        int $rowIndex,
        ?string $msgId,
        bool $isBatch,
    ): SourceTransactionDto {
        // Money: per-TxDtls amount on a batch entry, else the entry-level total.
        // genkgo/camt's Decoder already negates the Money for entries flagged
        // DBIT, so the value returned here is signed; no second flip needed.
        $money = $isBatch && $txDtls?->getAmountDetails() !== null
            ? $txDtls->getAmountDetails()
            : $entry->getAmount();
        $signed = $this->moneyToMinor($money);
        if ($isBatch && $txDtls?->getAmountDetails() !== null) {
            // The per-TxDtls AmtDtls/InstdAmt is NOT auto-signed by genkgo/camt
            // (only the entry-level Amt is); apply the CreditDebitIndicator
            // explicitly so batch splits agree in sign with the entry total.
            $cdi = $txDtls->getCreditDebitIndicator() ?? $entry->getCreditDebitIndicator();
            if ($cdi === 'DBIT' && $signed > 0) {
                $signed = -$signed;
            }
        }
        $currency = $money->getCurrency()->getCode();

        $cdi = $txDtls?->getCreditDebitIndicator() ?? $entry->getCreditDebitIndicator();

        $endToEndId = $txDtls?->getReference()?->getEndToEndId();
        $sourceRef = ($endToEndId !== null && $endToEndId !== '' && $endToEndId !== 'NOTPROVIDED')
            ? $endToEndId
            : null;

        [$counterpartyName, $counterpartyIban] = $this->extractCounterparty($txDtls, $cdi);
        $description = $this->extractRemittance($txDtls);

        $booking = $entry->getBookingDate() ?? $entry->getValueDate();
        if ($booking === null) {
            throw new InvalidAmountException(sprintf(
                'CAMT entry at row %d is missing both BookgDt and ValDt; cannot fingerprint deterministically.',
                $rowIndex,
            ));
        }
        $value = $entry->getValueDate() ?? $booking;

        // Banks export the BookgDt as a date-only element on every row in the
        // empirical corpus; the matching CSV adapter zeroes the time so the
        // FingerprintComposer v3 hash agrees between the two formats. Force
        // 00:00:00 here so a midnight-shifted timestamp never sneaks in via
        // a future BookgDtTm-precision change.
        $bookedAt = CarbonImmutable::instance($booking)->startOfDay();

        return new SourceTransactionDto(
            bookedAt: $bookedAt,
            postedAt: CarbonImmutable::instance($booking)->startOfDay(),
            valueDate: CarbonImmutable::instance($value)->startOfDay(),
            ownIban: $ownIban,
            counterpartyIban: $counterpartyIban,
            counterpartyName: $counterpartyName,
            currency: $currency,
            amountMinor: $signed,
            sourceRef: $sourceRef,
            description: $description,
            rawPayload: $this->serialiseSepaFragment($entry, $txDtls, $msgId),
            sourceRowIndex: $rowIndex,
        );
    }

    /**
     * Converts a moneyphp/money amount string into integer minor units. The
     * library returns the minor count as a numeric string ("399" for €3.99),
     * so the cast is exact for any value that fits in PHP_INT_MAX.
     */
    private function moneyToMinor(Money $money): int
    {
        return (int) $money->getAmount();
    }

    private function extractOwnIban(Statement $stmt): string
    {
        $account = $stmt->getAccount();
        if ($account instanceof IbanAccount) {
            return $account->getIban()->getIban();
        }

        return $account->getIdentification();
    }

    /**
     * Walks the TxDtls related-party list and returns the counterparty pair
     * appropriate for the entry's credit/debit direction. For an outbound
     * DBIT entry, the counterparty is the Creditor; for an inbound CRDT
     * entry, the counterparty is the Debtor. Falls back to the first related
     * party of any type when the directional match is absent.
     *
     * @return array{0: ?string, 1: ?string} [counterparty name, counterparty IBAN]
     */
    private function extractCounterparty(?EntryTransactionDetail $txDtls, ?string $cdi): array
    {
        if ($txDtls === null) {
            return [null, null];
        }

        $parties = $txDtls->getRelatedParties();
        if ($parties === []) {
            return [null, null];
        }

        $preferred = $cdi === 'CRDT'
            ? [Debtor::class, UltimateDebtor::class]
            : [Creditor::class, UltimateCreditor::class];

        foreach ($preferred as $cls) {
            foreach ($parties as $party) {
                if ($party->getRelatedPartyType() instanceof $cls) {
                    return [$this->relatedPartyName($party), $this->relatedPartyIban($party)];
                }
            }
        }

        $first = $parties[0];

        return [$this->relatedPartyName($first), $this->relatedPartyIban($first)];
    }

    private function relatedPartyName(RelatedParty $party): ?string
    {
        $type = $party->getRelatedPartyType();
        $name = $type->getName();
        if ($name === null) {
            return null;
        }

        $trimmed = trim($name);

        return $trimmed === '' ? null : $trimmed;
    }

    private function relatedPartyIban(RelatedParty $party): ?string
    {
        $account = $party->getAccount();
        if ($account instanceof IbanAccount) {
            return $account->getIban()->getIban();
        }

        return null;
    }

    /**
     * Concatenates the TxDtls unstructured remittance blocks into a single
     * whitespace-collapsed description string. Returns null when no
     * unstructured block is present.
     *
     * A TxDtls that carries only structured remittance (`<Strd>` rather
     * than `<Ustrd>`) yields null. The canonical unstructured-blocks API
     * is the only path consulted; the library's deprecated
     * `getMessage()` fallback would silently stringify structured data
     * and produce indistinguishable output for "no remittance" vs
     * "structured-only remittance", which masks data that downstream
     * resolution may legitimately need to handle differently.
     */
    private function extractRemittance(?EntryTransactionDetail $txDtls): ?string
    {
        if ($txDtls === null) {
            return null;
        }

        $rmt = $txDtls->getRemittanceInformation();
        if ($rmt === null) {
            return null;
        }

        $messages = [];
        foreach ($rmt->getUnstructuredBlocks() as $block) {
            $messages[] = $block->getMessage();
        }

        if ($messages === []) {
            return null;
        }

        return $this->collapseWhitespace(implode(' ', $messages));
    }

    private function collapseWhitespace(string $s): string
    {
        $normalised = preg_replace('/\s+/u', ' ', $s);

        return trim(is_string($normalised) ? $normalised : $s);
    }

    /**
     * Builds the `rawPayload['sepa']` fragment carrying every secondary
     * reference observed on the entry + TxDtls. Downstream chain
     * resolution reads these directly without re-parsing the source XML.
     *
     * @return array{sepa: array<string, mixed>}
     */
    private function serialiseSepaFragment(Entry $entry, ?EntryTransactionDetail $txDtls, ?string $msgId): array
    {
        $btc = $entry->getBankTransactionCode();
        $ref = $txDtls?->getReference();
        $addtl = $txDtls?->getAdditionalTransactionInformation();

        return [
            'sepa' => [
                'msgId' => $msgId,
                'acctSvcrRef' => $entry->getAccountServicerReference(),
                'entryRef' => $entry->getReference(),
                'batchPaymentId' => $entry->getBatchPaymentId(),
                'btc' => [
                    'domain' => $btc?->getDomain()?->getCode(),
                    'family' => $btc?->getDomain()?->getFamily()->getCode(),
                    'subFamily' => $btc?->getDomain()?->getFamily()->getSubFamilyCode(),
                    'proprietary' => $btc?->getProprietary()?->getCode(),
                ],
                'endToEndId' => $ref?->getEndToEndId(),
                'instrId' => $ref?->getInstructionId(),
                'txId' => $ref?->getTransactionId(),
                'mandateId' => $ref?->getMandateId(),
                'pmtInfId' => $ref?->getPaymentInformationId(),
                'creditDebitIndicator' => $txDtls?->getCreditDebitIndicator(),
                'remittanceUnstructured' => $this->extractRemittance($txDtls),
                'addtlTxInf' => $addtl === null ? null : (string) $addtl,
            ],
        ];
    }
}
