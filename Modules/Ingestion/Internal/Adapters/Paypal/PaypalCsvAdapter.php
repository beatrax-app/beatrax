<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Carbon\CarbonImmutable;
use Generator;
use League\Csv\CharsetConverter;
use League\Csv\Reader;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Exceptions\UnsupportedPaypalCsvLanguageException;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Dto\StatementSummaryData;

/**
 * @link ../../../../../.docs/features/ingestion/architecture.md
 */
final class PaypalCsvAdapter implements SourceAdapter
{
    // PayPal wallets have no real IBAN; AccountResolver scopes lookups by
    // (iban, user_id), so a single per-instance literal is unambiguous.
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

        // Coerce the header to a positional list of strings — league/csv
        // returns an array<int, string> but PHPStan widens it to
        // array<int|string, string> by default. Explicit array_values
        // pins the list shape PaypalCsvLanguageProfile::detect expects.
        $headerColumns = array_values($reader->getHeader());
        // Strip a leading byte-order mark from the first header cell — the
        // empirical Activity Download CSV ships one, and CharsetConverter
        // does not strip it since the source encoding is already Unicode.
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

        // Buffer all rows: the walker needs the full set to resolve
        // Reference-Txn-ID parent/child links before emitting canonical
        // DTOs, so row-by-row yielding isn't possible here. Coerce
        // league/csv's string|null ragged-row cells to empty string.
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

        $accounts->resolve(self::PAYPAL_OWN_IBAN);

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

    // PayPal ships no opening/closing balance rows, so closing = sum(net)
    // and opening = 0 are bookkeeping placeholders only; the walker
    // counters in $extras carry the real audit signal for this format.
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
            openingBalanceCurrency: 'EUR',
            openingBalanceDate: $periodStart,
            closingBalanceMinor: $netSumMinor,
            closingBalanceCurrency: 'EUR',
            closingBalanceDate: $periodEnd,
            entryCount: $entryCount,
            extras: $extras,
        );
    }
}
