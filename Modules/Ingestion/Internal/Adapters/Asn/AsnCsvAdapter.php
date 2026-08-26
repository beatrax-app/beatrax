<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn;

use Carbon\CarbonImmutable;
use Generator;
use League\Csv\CharsetConverter;
use League\Csv\Reader;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ingestion\Internal\Adapters\Banking\BankAmountParser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Asn\AsnDescriptionDelimiters;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Dto\StatementSummaryData;
use Modules\Ledger\Public\Enums\Currency;
use Throwable;

final class AsnCsvAdapter implements SourceAdapter
{
    public function __construct(
        private readonly BankAmountParser $amounts,
        private readonly HeaderSniffer $sniffer,
    ) {}

    public function format(): string
    {
        return AsnCsvHeaderProfile::FORMAT;
    }

    /**
     * @return null Always — ASN's CSV export carries no file-level totals.
     */
    public function statementMetadata(): ?StatementSummaryData
    {
        return null;
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
    {
        $this->sniffer->sniff($localPath, AsnCsvHeaderProfile::FORMAT);

        $reader = Reader::from($localPath, 'r');
        $reader->setDelimiter(AsnCsvHeaderProfile::DELIMITER);
        $reader->setEscape('');
        $reader->setHeaderOffset(0);
        CharsetConverter::addTo($reader, AsnCsvHeaderProfile::SOURCE_ENCODING, 'UTF-8');

        $index = 0;
        foreach ($reader->getRecords() as $record) {
            $row = $this->normaliseRow($record);

            try {
                $postedAt = $this->parseDate($row[AsnCsvColumnMap::POSTED_DATE]);
                $valueDate = $this->parseDate($row[AsnCsvColumnMap::VALUE_DATE]);
                $amountMinor = $this->amounts->parseMinor($row[AsnCsvColumnMap::AMOUNT]);
            } catch (Throwable $e) {
                throw new InvalidAmountException(
                    sprintf('Row %d: %s', $index, $e->getMessage()),
                    0,
                    $e,
                );
            }

            $ownIban = $row[AsnCsvColumnMap::OWN_IBAN];

            $currency = $row[AsnCsvColumnMap::MUTATION_CURRENCY];
            if ($currency === '') {
                $currency = Currency::Eur->value;
            }

            yield new SourceTransactionDto(
                bookedAt: $postedAt->startOfDay(),
                postedAt: $postedAt,
                valueDate: $valueDate,
                ownIban: $ownIban,
                counterpartyIban: $this->nullIfEmpty($row[AsnCsvColumnMap::COUNTERPARTY_IBAN]),
                counterpartyName: $this->nullIfEmpty(trim($row[AsnCsvColumnMap::COUNTERPARTY_NAME])),
                currency: $currency,
                amountMinor: $amountMinor,
                sourceRef: $this->nullIfEmpty($row[AsnCsvColumnMap::SEQUENCE_NUMBER]),
                description: $this->joinDescription($row),
                rawPayload: $row,
                sourceRowIndex: $index,
            );

            $index++;
        }
    }

    /**
     * @return array<int, string>
     */
    private function normaliseRow(mixed $record): array
    {
        if (! is_array($record)) {
            throw new InvalidAmountException('Unexpected non-array record from CSV reader.');
        }

        $row = [];
        foreach (array_values($record) as $cell) {
            if ($cell === null) {
                $row[] = '';
            } elseif (is_string($cell)) {
                $row[] = $cell;
            } else {
                throw new InvalidAmountException(sprintf(
                    'Unexpected non-string cell in CSV row (got %s).',
                    get_debug_type($cell),
                ));
            }
        }

        return $row;
    }

    private function parseDate(string $cell): CarbonImmutable
    {
        $parsed = SafeDate::fromFormatOrNull('!'.AsnCsvHeaderProfile::DATE_FORMAT, $cell);
        if (! $parsed instanceof CarbonImmutable) {
            throw new InvalidAmountException(sprintf(
                "Cannot parse date '%s' (expected format %s)",
                $cell,
                AsnCsvHeaderProfile::DATE_FORMAT,
            ));
        }

        return $parsed;
    }

    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function joinDescription(array $row): ?string
    {
        $parts = [];
        foreach ([AsnCsvColumnMap::PAYMENT_REF, AsnCsvColumnMap::DESCRIPTION] as $col) {
            $value = AsnDescriptionDelimiters::unwrap(trim($row[$col]));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        if ($parts === []) {
            return null;
        }

        $combined = implode(AsnDescriptionDelimiters::SEPARATOR, $parts);
        // ASN historically emits literal \r within this field; normalise
        // both CR and LF to a single space before collapsing whitespace.
        $combined = str_replace(["\r", "\n"], ' ', $combined);
        $collapsed = preg_replace('/\s+/u', ' ', $combined);

        return is_string($collapsed) ? trim($collapsed) : trim($combined);
    }
}
