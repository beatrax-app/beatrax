<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Dto;

use Modules\Ledger\Public\Enums\Currency;

final readonly class CsvPreset
{
    public const string SIGNED = 'signed';

    public const string DEBIT_CREDIT = 'debit_credit';

    public const string INDICATOR = 'indicator';

    /**
     * @param  string  $format  stable lowercase-kebab format id (e.g. 'n26-csv')
     * @param  list<string>  $headerSignature  header cells that must all be present (sniffing)
     * @param  list<string>  $descriptionHeaders  columns concatenated into the description
     * @param  self::SIGNED|self::DEBIT_CREDIT|self::INDICATOR  $amountStrategy
     * @param  list<string>  $acceptedStates  when $stateHeader is set, only rows whose state is in this list are imported (others skipped)
     */
    public function __construct(
        public string $format,
        public string $label,
        public array $headerSignature,
        public string $dateHeader,
        public string $dateFormat,
        public string $amountStrategy,
        public string $decimalSeparator = '.',
        public string $delimiter = ',',
        public string $encoding = 'UTF-8',
        public array $descriptionHeaders = [],
        public ?string $amountHeader = null,
        public ?string $debitHeader = null,
        public ?string $creditHeader = null,
        public ?string $indicatorHeader = null,
        public ?string $debitIndicator = null,
        public ?string $creditIndicator = null,
        public ?string $valueDateHeader = null,
        public ?string $counterpartyNameHeader = null,
        public ?string $counterpartyIbanHeader = null,
        public ?string $currencyHeader = null,
        public string $fixedCurrency = Currency::Eur->value,
        public ?string $ownIbanHeader = null,
        public ?string $sourceRefHeader = null,
        public ?string $feeHeader = null,
        public ?string $stateHeader = null,
        public array $acceptedStates = [],
    ) {}

    // What the account this file belongs to is called when the export carries
    // no own-IBAN column, as single-account fintech exports (N26, Revolut,
    // Wise) do not. It is not an IBAN and must not be validated as one:
    // AccountNamer asks the registry whether a value is one of these.
    public function ownAccountIdentifier(): string
    {
        return strtoupper(str_replace('-csv', '', $this->format));
    }

    // Every column GenericCsvAdapter addresses through cell(), whose miss is a
    // throw, plus the signature that discriminates this bank from the next. Held
    // here because the sniff and the row parser have to agree on it: a required
    // column left out of the check surfaced as a detail-free FileStoppedShort.
    /**
     * @return list<string>
     */
    public function requiredHeaders(): array
    {
        $required = [...$this->headerSignature, $this->dateHeader, ...$this->descriptionHeaders];

        foreach ([$this->ownIbanHeader, $this->valueDateHeader, $this->currencyHeader] as $header) {
            if ($header !== null) {
                $required[] = $header;
            }
        }

        if ($this->stateHeader !== null && $this->acceptedStates !== []) {
            $required[] = $this->stateHeader;
        }

        $required = [...$required, ...$this->amountStrategyHeaders()];

        return array_values(array_unique($required));
    }

    /**
     * @return list<string>
     */
    private function amountStrategyHeaders(): array
    {
        $headers = match ($this->amountStrategy) {
            self::DEBIT_CREDIT => [$this->debitHeader, $this->creditHeader],
            self::INDICATOR => [$this->amountHeader, $this->indicatorHeader],
            default => [$this->amountHeader],
        };

        return array_values(array_filter($headers, static fn (?string $h): bool => $h !== null));
    }

    public static function normaliseHeader(string $header): string
    {
        $lowered = mb_strtolower(trim($header));

        return preg_replace('/\s+/u', '', $lowered) ?? $lowered;
    }
}
