<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Csv;

use Carbon\CarbonImmutable;
use Generator;
use League\Csv\CharsetConverter;
use League\Csv\Reader;
use League\Csv\SyntaxError;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Internal\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Dto\CsvPreset;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Dto\StatementSummaryData;
use Throwable;

final class GenericCsvAdapter implements SourceAdapter
{
    public function __construct(
        private readonly CsvPreset $preset,
        private readonly GenericCsvAmountParser $amounts,
        private readonly HeaderSniffer $sniffer,
    ) {}

    public function format(): string
    {
        return $this->preset->format;
    }

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

        try {
            // getHeader() throws on duplicate column names; surface that as a
            // user-facing sniff message instead of a raw 500.
            $normMap = $this->buildHeaderMap($reader->getHeader());
            $records = $reader->getRecords();
        } catch (SyntaxError $e) {
            throw new SniffMismatchException(sprintf(
                'The %s CSV could not be read (it may have duplicate or malformed column headers).',
                $this->preset->label,
            ), $e);
        }

        $index = 0;
        foreach ($records as $record) {
            /** @var array<string, string|null> $record */
            if ($this->rejectedByState($record, $normMap)) {
                continue;
            }

            $dateCell = trim($this->cell($record, $normMap, $this->preset->dateHeader));
            if ($dateCell === '') {
                // An incomplete/pending row (no booking date) is skipped, never fatal to the import.
                continue;
            }

            try {
                $postedAt = $this->parseDate($dateCell);
                $valueDate = $this->preset->valueDateHeader !== null
                    ? $this->parseDateOrFallback($record, $normMap, $this->preset->valueDateHeader, $postedAt)
                    : $postedAt;
                $amountMinor = $this->amountMinor($record, $normMap);
            } catch (Throwable $e) {
                throw new InvalidAmountException(sprintf('Row %d: %s', $index, $e->getMessage()), 0, $e);
            }

            $ownIban = $this->ownIban($record, $normMap);
            $accounts->resolve($ownIban);

            yield new SourceTransactionDto(
                bookedAt: $postedAt->startOfDay(),
                postedAt: $postedAt,
                valueDate: $valueDate,
                ownIban: $ownIban,
                counterpartyIban: $this->optionalCell($record, $normMap, $this->preset->counterpartyIbanHeader),
                counterpartyName: $this->optionalCell($record, $normMap, $this->preset->counterpartyNameHeader),
                currency: $this->currency($record, $normMap),
                amountMinor: $amountMinor,
                sourceRef: $this->optionalCell($record, $normMap, $this->preset->sourceRefHeader),
                description: $this->description($record, $normMap),
                rawPayload: $this->stringRecord($record),
                sourceRowIndex: $index,
            );

            $index++;
        }
    }

    /**
     * @param  array<array-key, string>  $header
     * @return array<string, string> normalised name => actual header cell
     */
    private function buildHeaderMap(array $header): array
    {
        $map = [];
        foreach ($header as $cell) {
            $map[$this->normalise($cell)] = $cell;
        }

        return $map;
    }

    private function normalise(string $header): string
    {
        return CsvPreset::normaliseHeader($header);
    }

    /**
     * @param  array<string, string|null>  $record
     * @param  array<string, string>  $normMap
     */
    private function rejectedByState(array $record, array $normMap): bool
    {
        if ($this->preset->stateHeader === null || $this->preset->acceptedStates === []) {
            return false;
        }

        $state = trim($this->cell($record, $normMap, $this->preset->stateHeader));

        return $state !== '' && ! in_array($state, $this->preset->acceptedStates, strict: true);
    }

    /**
     * @param  array<string, string|null>  $record
     * @param  array<string, string>  $normMap
     */
    private function amountMinor(array $record, array $normMap): int
    {
        $sep = $this->preset->decimalSeparator;

        return match ($this->preset->amountStrategy) {
            CsvPreset::DEBIT_CREDIT => $this->debitCreditAmount($record, $normMap, $sep),
            CsvPreset::INDICATOR => $this->indicatorAmount($record, $normMap, $sep),
            default => $this->amounts->parseMinor($this->cell($record, $normMap, (string) $this->preset->amountHeader), $sep),
        };
    }

    /**
     * @param  array<string, string|null>  $record
     * @param  array<string, string>  $normMap
     */
    private function debitCreditAmount(array $record, array $normMap, string $sep): int
    {
        $debit = trim($this->cell($record, $normMap, (string) $this->preset->debitHeader));
        $credit = trim($this->cell($record, $normMap, (string) $this->preset->creditHeader));

        if ($debit !== '') {
            return -abs($this->amounts->parseMinor($debit, $sep));
        }
        if ($credit !== '') {
            return abs($this->amounts->parseMinor($credit, $sep));
        }

        throw new InvalidAmountException('Both debit and credit columns are empty.');
    }

    /**
     * @param  array<string, string|null>  $record
     * @param  array<string, string>  $normMap
     */
    private function indicatorAmount(array $record, array $normMap, string $sep): int
    {
        $magnitude = abs($this->amounts->parseMinor($this->cell($record, $normMap, (string) $this->preset->amountHeader), $sep));
        $indicator = trim($this->cell($record, $normMap, (string) $this->preset->indicatorHeader));

        if (strcasecmp($indicator, (string) $this->preset->debitIndicator) === 0) {
            return -$magnitude;
        }
        if ($this->preset->creditIndicator === null || strcasecmp($indicator, $this->preset->creditIndicator) === 0) {
            return $magnitude;
        }

        throw new InvalidAmountException(sprintf(
            "Unrecognised direction indicator '%s' (expected '%s' or '%s').",
            $indicator,
            (string) $this->preset->debitIndicator,
            $this->preset->creditIndicator,
        ));
    }

    /**
     * @param  array<string, string|null>  $record
     * @param  array<string, string>  $normMap
     */
    private function currency(array $record, array $normMap): string
    {
        if ($this->preset->currencyHeader !== null) {
            $fromRow = trim($this->cell($record, $normMap, $this->preset->currencyHeader));
            if ($fromRow !== '') {
                return $fromRow;
            }
        }

        return $this->preset->fixedCurrency;
    }

    /**
     * @param  array<string, string|null>  $record
     * @param  array<string, string>  $normMap
     */
    private function ownIban(array $record, array $normMap): string
    {
        if ($this->preset->ownIbanHeader !== null) {
            $value = trim($this->cell($record, $normMap, $this->preset->ownIbanHeader));
            if ($value !== '') {
                return $value;
            }
        }

        // Single-account fintech exports (N26, Revolut, Wise) carry no own
        // IBAN; use a stable synthetic so the wizard maps it to one account.
        return strtoupper(str_replace('-csv', '', $this->preset->format));
    }

    /**
     * @param  array<string, string|null>  $record
     * @param  array<string, string>  $normMap
     */
    private function description(array $record, array $normMap): ?string
    {
        $parts = [];
        foreach ($this->preset->descriptionHeaders as $header) {
            $value = trim($this->cell($record, $normMap, $header));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        if ($parts === []) {
            return null;
        }

        $combined = preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', implode(' / ', $parts)));

        return is_string($combined) ? trim($combined) : trim(implode(' / ', $parts));
    }

    /**
     * @param  array<string, string|null>  $record
     * @param  array<string, string>  $normMap
     */
    private function cell(array $record, array $normMap, string $header): string
    {
        $actual = $normMap[$this->normalise($header)] ?? null;
        if ($actual === null || ! array_key_exists($actual, $record)) {
            throw new InvalidAmountException(sprintf("Missing expected column '%s'.", $header));
        }
        $value = $record[$actual];

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, string|null>  $record
     * @param  array<string, string>  $normMap
     */
    private function optionalCell(array $record, array $normMap, ?string $header): ?string
    {
        if ($header === null) {
            return null;
        }
        $actual = $normMap[$this->normalise($header)] ?? null;
        if ($actual === null) {
            return null;
        }
        $raw = $record[$actual] ?? null;
        $value = is_string($raw) ? trim($raw) : '';

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, string|null>  $record
     * @param  array<string, string>  $normMap
     */
    private function parseDateOrFallback(array $record, array $normMap, string $header, CarbonImmutable $fallback): CarbonImmutable
    {
        $cell = trim($this->cell($record, $normMap, $header));

        return $cell === '' ? $fallback : $this->parseDate($cell);
    }

    private function parseDate(string $cell): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!'.$this->preset->dateFormat, trim($cell));
        if (! $parsed instanceof CarbonImmutable) {
            throw new InvalidAmountException(sprintf(
                "Cannot parse date '%s' (expected format %s).",
                $cell,
                $this->preset->dateFormat,
            ));
        }

        return $parsed;
    }

    /**
     * @param  array<string, string|null>  $record
     * @return array<string, string>
     */
    private function stringRecord(array $record): array
    {
        $out = [];
        foreach ($record as $key => $value) {
            $out[$key] = is_string($value) ? $value : '';
        }

        return $out;
    }
}
