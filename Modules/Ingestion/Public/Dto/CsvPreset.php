<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Dto;

/**
 * Declarative description of one bank's / fintech's CSV export: the dialect
 * (delimiter, encoding, header), how to read each canonical field from a
 * named column, and how the amount + its sign are encoded. A single
 * GenericCsvAdapter is parameterised by one of these, so adding a new bank
 * is data, not code.
 *
 * Columns are addressed by HEADER NAME (the file must have a header row);
 * the GenericCsvAdapter reads league/csv records associatively.
 *
 * Amount strategies:
 *   - SIGNED:       one column carries a signed number (N26, Revolut, Wise).
 *   - DEBIT_CREDIT: separate debit + credit columns, each unsigned; the one
 *                   that is non-empty determines the sign (many DE banks).
 *   - INDICATOR:    an unsigned amount column plus a direction column whose
 *                   value equals `debitIndicator` for outgoing (ING NL "Af").
 */
final class CsvPreset
{
    public const SIGNED = 'signed';

    public const DEBIT_CREDIT = 'debit_credit';

    public const INDICATOR = 'indicator';

    /**
     * @param  string  $format  stable lowercase-kebab format id (e.g. 'n26-csv')
     * @param  list<string>  $headerSignature  header cells that must all be present (sniffing)
     * @param  list<string>  $descriptionHeaders  columns concatenated into the description
     * @param  self::SIGNED|self::DEBIT_CREDIT|self::INDICATOR  $amountStrategy
     * @param  list<string>  $acceptedStates  when $stateHeader is set, only rows whose state is in this list are imported (others skipped)
     */
    public function __construct(
        public readonly string $format,
        public readonly string $label,
        public readonly string $issuer,
        public readonly array $headerSignature,
        public readonly string $dateHeader,
        public readonly string $dateFormat,
        public readonly string $amountStrategy,
        public readonly string $decimalSeparator = '.',
        public readonly string $delimiter = ',',
        public readonly string $encoding = 'UTF-8',
        public readonly array $descriptionHeaders = [],
        public readonly ?string $amountHeader = null,
        public readonly ?string $debitHeader = null,
        public readonly ?string $creditHeader = null,
        public readonly ?string $indicatorHeader = null,
        public readonly ?string $debitIndicator = null,
        public readonly ?string $creditIndicator = null,
        public readonly ?string $valueDateHeader = null,
        public readonly ?string $counterpartyNameHeader = null,
        public readonly ?string $counterpartyIbanHeader = null,
        public readonly ?string $currencyHeader = null,
        public readonly string $fixedCurrency = 'EUR',
        public readonly ?string $ownIbanHeader = null,
        public readonly ?string $sourceRefHeader = null,
        public readonly ?string $feeHeader = null,
        public readonly ?string $stateHeader = null,
        public readonly array $acceptedStates = [],
    ) {}

    /**
     * Canonical form for comparing a header cell, tolerant of the minor
     * spelling differences banks ship: lower-cased and stripped of all
     * whitespace, so `Naam / Omschrijving` == `Naam/Omschrijving` and
     * `MutatieSoort` == `Mutatiesoort`.
     */
    public static function normaliseHeader(string $header): string
    {
        $lowered = mb_strtolower(trim($header));

        return preg_replace('/\s+/u', '', $lowered) ?? $lowered;
    }
}
