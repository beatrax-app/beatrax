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
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Dto\StatementSummaryData;
use Money\Money;
use Throwable;

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
     * @return ?StatementSummaryData the most recent parse() run's metadata,
     *                               with importRunId/accountId as zeroed placeholders the pipeline
     *                               overrides via withImportRunId()/withAccountId()
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

    private function readMessage(string $localPath): Message
    {
        // Denying every external entity closes XXE on untrusted statement XML;
        // XSD validation is disabled below, so nothing legitimate needs to
        // resolve. The finally clause puts the process-wide loader back.
        libxml_set_external_entity_loader(
            static fn (?string $publicId, ?string $systemId, array $context): ?string => null
        );

        $previousErrorState = libxml_use_internal_errors(true);
        try {
            // genkgo/camt's XSDs would reject unforeseen optional elements; the
            // sniffer plus the IBAN/amount validators enforce structure instead.
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
        // genkgo/camt's Decoder already negates the entry-level Money for a
        // DBIT entry, so this value arrives signed; no second flip needed.
        $money = $isBatch && $txDtls?->getAmountDetails() !== null
            ? $txDtls->getAmountDetails()
            : $entry->getAmount();
        $signed = $this->moneyToMinor($money);
        if ($isBatch && $txDtls?->getAmountDetails() !== null) {
            // AmtDtls/InstdAmt is not auto-signed the way the entry-level Amt is, so
            // applying the indicator by hand keeps a batch split's sign matching its entry total.
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

        // The import fingerprint hashes the booking timestamp, so a CAMT row and
        // the same row from a CSV export must both be zeroed to startOfDay() to
        // land on one fingerprint and dedupe.
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
     * @return array{0: ?string, 1: ?string} [counterparty name, counterparty IBAN]
     */
    private function extractCounterparty(?EntryTransactionDetail $txDtls, ?string $cdi): array
    {
        $parties = $txDtls?->getRelatedParties() ?? [];
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

    // Deliberately not the deprecated getMessage() fallback: that stringifies structured
    // <Strd> remittance, hiding "no remittance" behind "structured-only".
    private function extractRemittance(?EntryTransactionDetail $txDtls): ?string
    {
        $rmt = $txDtls?->getRemittanceInformation();
        if ($rmt === null) {
            return null;
        }

        $messages = [];
        foreach ($rmt->getUnstructuredBlocks() as $block) {
            $messages[] = $block->getMessage();
        }

        return $messages === [] ? null : $this->collapseWhitespace(implode(' ', $messages));
    }

    private function collapseWhitespace(string $s): string
    {
        $normalised = preg_replace('/\s+/u', ' ', $s);

        return trim(is_string($normalised) ? $normalised : $s);
    }

    /**
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
