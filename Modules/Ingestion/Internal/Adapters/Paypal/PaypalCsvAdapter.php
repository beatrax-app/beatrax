<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Carbon\CarbonImmutable;
use Generator;
use League\Csv\CharsetConverter;
use League\Csv\Reader;
use Modules\Ingestion\Internal\Exceptions\UnsupportedPaypalCsvLanguageException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Dto\StatementSummaryData;
use Modules\Ledger\Public\Enums\Currency;

final class PaypalCsvAdapter implements SourceAdapter
{
    // A PayPal wallet has no IBAN, so this literal stands in as the account
    // key; AccountResolver scopes by user, so it cannot collide.
    private const PAYPAL_OWN_IBAN = 'PAYPAL';

    private ?StatementSummaryData $lastStatementMetadata = null;

    public function __construct(
        private readonly HeaderSniffer $sniffer,
        private readonly PaypalTransactionRollup $rollup,
    ) {}

    public function format(): string
    {
        return PaypalCsvLanguageProfile::FORMAT;
    }

    public function statementMetadata(): ?StatementSummaryData
    {
        return $this->lastStatementMetadata;
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
    {
        $this->sniffer->sniff($localPath, PaypalCsvLanguageProfile::FORMAT);
        $this->lastStatementMetadata = null;

        $reader = Reader::createFromPath($localPath, 'r');
        $reader->setDelimiter(PaypalCsvLanguageProfile::DELIMITER);
        $reader->setEscape('');
        $reader->setHeaderOffset(0);
        CharsetConverter::addTo($reader, PaypalCsvLanguageProfile::SOURCE_ENCODING, 'UTF-8');

        // array_values pins the list shape detect() expects; PHPStan widens
        // getHeader() to array<int|string, string>.
        $headerColumns = array_values($reader->getHeader());
        // The Activity Download CSV ships a BOM on the first header cell, and
        // CharsetConverter leaves it since the source encoding is already Unicode.
        if (isset($headerColumns[0])) {
            $headerColumns[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headerColumns[0]) ?? $headerColumns[0];
        }

        $languageProfile = PaypalCsvLanguageProfile::detect($headerColumns);
        if ($languageProfile === null) {
            throw new UnsupportedPaypalCsvLanguageException(
                'PayPal CSV header tokens did not match any registered language profile. '
                .'Supported locales: '.implode(', ', PaypalCsvLanguageProfile::supported())
                .'. If your account exports in a different language, file an issue with the redacted CSV.'
            );
        }
        $language = $languageProfile->detected();

        // Buffered, not yielded: the rollup walker needs the full set to resolve
        // Reference-Txn-ID parent/child links before any DTO can be emitted.
        /** @var list<array<string, string>> $rawRows */
        $rawRows = [];
        foreach ($reader->getRecords() as $record) {
            /** @var array<string, string> $assoc */
            $assoc = [];
            /** @var array<string, string|null> $record */
            foreach ($record as $key => $cell) {
                $assoc[$key] = $cell ?? '';
            }
            $rawRows[] = $assoc;
        }

        $rolledUp = $this->rollup->rollup($rawRows, $language);

        /** @var list<CarbonImmutable> $bookedDates */
        $bookedDates = [];
        $netSumMinor = 0;
        $count = 0;
        foreach ($rolledUp as $dto) {
            yield $dto;
            $bookedDates[] = $dto->bookedAt;
            $netSumMinor += $dto->amountMinor;
            $count++;
        }

        $this->lastStatementMetadata = $this->buildStatementMetadata(
            bookedDates: $bookedDates,
            netSumMinor: $netSumMinor,
            entryCount: $count,
            language: $language,
        );
    }

    // PayPal ships no opening/closing balance rows, so closing = sum(net) and opening = 0
    // are placeholders; the walker counters in $extras carry this format's audit signal.
    /**
     * @param  list<CarbonImmutable>  $bookedDates
     */
    private function buildStatementMetadata(
        array $bookedDates,
        int $netSumMinor,
        int $entryCount,
        string $language,
    ): StatementSummaryData {
        if ($bookedDates !== []) {
            $sorted = $bookedDates;
            usort($sorted, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);
            $periodStart = $sorted[0];
            $periodEnd = $sorted[count($sorted) - 1];
        } else {
            $periodStart = null;
            $periodEnd = null;
        }

        $extras = [
            'language' => $language,
            'skippedHoldCount' => $this->rollup->skippedHoldCount(),
            'orphanChildCount' => $this->rollup->orphanChildCount(),
        ];

        $skippedMalformedRowCount = $this->rollup->skippedMalformedRowCount();
        if ($skippedMalformedRowCount > 0) {
            $extras['skippedMalformedRowCount'] = $skippedMalformedRowCount;
        }

        return new StatementSummaryData(
            importRunId: 0,
            accountId: 0,
            ibanOwner: self::PAYPAL_OWN_IBAN,
            statementNumber: null,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            openingBalanceMinor: 0,
            openingBalanceCurrency: Currency::Eur->value,
            openingBalanceDate: $periodStart,
            closingBalanceMinor: $netSumMinor,
            closingBalanceCurrency: Currency::Eur->value,
            closingBalanceDate: $periodEnd,
            entryCount: $entryCount,
            extras: $extras,
        );
    }
}
