<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Dto;

// The sibling of CsvPreset for an export read by column POSITION rather than by
// header name. Both describe one bank's CSV dialect as data; which of the two a
// bank needs is a property of its export, not of the bank.
final readonly class PositionalCsvPreset
{
    /**
     * @param  string  $format  stable lowercase-kebab format id, the adapter key and the value a picker submits
     * @param  string  $label  the issuer's own name, for the sniff message — data, never an identifier
     * @param  list<string>  $headerSignature  leading header cells that must match exactly, in order
     * @param  list<int>  $acceptedColumnCounts  every column count the export has shipped
     * @param  list<int>  $descriptionColumns  zero-based indices concatenated into the description, in order
     */
    public function __construct(
        public string $format,
        public string $label,
        public array $headerSignature,
        public array $acceptedColumnCounts,
        public string $dateFormat,
        public int $postedDateColumn,
        public int $ownIbanColumn,
        public int $amountColumn,
        public int $valueDateColumn,
        public int $currencyColumn,
        public array $descriptionColumns = [],
        public ?int $counterpartyIbanColumn = null,
        public ?int $counterpartyNameColumn = null,
        public ?int $sourceRefColumn = null,
        public string $delimiter = ',',
        public string $encoding = 'UTF-8',
    ) {}
}
