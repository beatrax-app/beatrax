<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Csv;

use Carbon\CarbonImmutable;
use Generator;
use League\Csv\CharsetConverter;
use League\Csv\Reader;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ingestion\Internal\Adapters\Banking\BankAmountParser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Csv\AsnDescriptionDelimiters;
use Modules\Ingestion\Public\Dto\PositionalCsvPreset;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Dto\StatementSummaryData;
use Modules\Ledger\Public\Enums\Currency;
use Throwable;

// Reads a CSV whose columns are addressed by position. The header row is present
// and is what the sniff matches on, but the cells are taken by index, because an
// export that renames a column between revisions keeps its order.
final readonly class PositionalCsvAdapter implements SourceAdapter
{
    public function __construct(
        private PositionalCsvPreset $preset,
        private BankAmountParser $amounts,
        private HeaderSniffer $sniffer,
    ) {}

    public function format(): string
    {
        return $this->preset->format;
    }

    /**
     * @return null Always — a positional CSV export carries no file-level totals.
     */
    public function statementMetadata(): ?StatementSummaryData
    {
        return null;
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
    {
        $this->sniffer->sniff($localPath, $this->preset->format);

        $reader = Reader::from($localPath, 'r');
        $reader->setDelimiter($this->preset->delimiter);
        $reader->setEscape('');
        $reader->setHeaderOffset(0);
        CharsetConverter::addTo($reader, $this->preset->encoding, 'UTF-8');

        $index = 0;
        foreach ($reader->getRecords() as $record) {
            $row = $this->normaliseRow($record);

            // Resolved before the amount, not after: the row's own currency is
            // what says how many minor units one major unit holds.
            $currency = $row[$this->preset->currencyColumn];
            if ($currency === '') {
                $currency = Currency::Eur->value;
            }

            try {
                $postedAt = $this->parseDate($row[$this->preset->postedDateColumn]);
                $valueDate = $this->parseDate($row[$this->preset->valueDateColumn]);
                $amountMinor = $this->amounts->parseMinor($row[$this->preset->amountColumn], $currency);
            } catch (Throwable $e) {
                throw new InvalidAmountException(
                    sprintf('Row %d: %s', $index, $e->getMessage()),
                    0,
                    $e,
                );
            }

            yield new SourceTransactionDto(
                bookedAt: $postedAt->startOfDay(),
                postedAt: $postedAt,
                valueDate: $valueDate,
                ownIban: $row[$this->preset->ownIbanColumn],
                counterpartyIban: $this->cellOrNull($row, $this->preset->counterpartyIbanColumn),
                counterpartyName: $this->cellOrNull($row, $this->preset->counterpartyNameColumn, trim: true),
                currency: $currency,
                amountMinor: $amountMinor,
                sourceRef: $this->cellOrNull($row, $this->preset->sourceRefColumn),
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
        $parsed = SafeDate::fromFormatOrNull('!'.$this->preset->dateFormat, $cell);
        if (! $parsed instanceof CarbonImmutable) {
            throw new InvalidAmountException(sprintf(
                "Cannot parse date '%s' (expected format %s)",
                $cell,
                $this->preset->dateFormat,
            ));
        }

        return $parsed;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function cellOrNull(array $row, ?int $column, bool $trim = false): ?string
    {
        if ($column === null) {
            return null;
        }

        $value = $trim ? trim($row[$column]) : $row[$column];

        return $value === '' ? null : $value;
    }

    // unwrap() strips only a MATCHING apostrophe pair, so a preset whose issuer
    // does not delimit this way passes through untouched.
    /**
     * @param  array<int, string>  $row
     */
    private function joinDescription(array $row): ?string
    {
        $parts = [];
        foreach ($this->preset->descriptionColumns as $col) {
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
