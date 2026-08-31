<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Services;

use Modules\Ingestion\Public\Dto\CsvPreset;
use Modules\Ingestion\Public\Dto\PositionalCsvPreset;
use Modules\Ledger\Public\Enums\Currency;

final class CsvPresetRegistry
{
    // A preset id is the adapter key the registry binds and the value every
    // picker submits, so it is named here rather than spelled out at each of
    // those call sites.
    public const string N26 = 'n26-csv';

    public const string REVOLUT = 'revolut-csv';

    public const string ING_NL = 'ing-nl-csv';

    public const string ASN = 'asn-csv';

    /** @var array<string, CsvPreset>|null */
    private ?array $presets = null;

    /** @var array<string, PositionalCsvPreset>|null */
    private ?array $positionalPresets = null;

    /**
     * @return array<string, CsvPreset> keyed by format id
     */
    public function all(): array
    {
        return $this->presets ??= $this->build();
    }

    public function get(string $format): ?CsvPreset
    {
        return $this->all()[$format] ?? null;
    }

    /**
     * @return array<string, PositionalCsvPreset> keyed by format id
     */
    public function allPositional(): array
    {
        return $this->positionalPresets ??= $this->buildPositional();
    }

    public function positional(string $format): ?PositionalCsvPreset
    {
        return $this->allPositional()[$format] ?? null;
    }

    // Every CSV layout a reader can pick, whichever way its columns are
    // addressed. A picker wants the union and only reads ->format and ->label,
    // so it has no reason to know which of the two kinds it is holding.
    /**
     * @return array<string, CsvPreset|PositionalCsvPreset> keyed by format id
     */
    public function allLayouts(): array
    {
        return [...$this->all(), ...$this->allPositional()];
    }

    public function layout(string $format): CsvPreset|PositionalCsvPreset|null
    {
        return $this->allLayouts()[$format] ?? null;
    }

    public function has(string $format): bool
    {
        return isset($this->allLayouts()[$format]);
    }

    // True when this is a placeholder a preset issued because its export
    // carries no own-IBAN column, and so must not be held to an IBAN's shape.
    public function issuesOwnAccountIdentifier(string $value): bool
    {
        return $this->ownAccountLabel($value) !== null;
    }

    // The bank behind such a placeholder, for a screen that has to put one in
    // front of a reader: the placeholder is a format id in upper case and
    // names nothing the reader has ever seen. Null when no preset issued it.
    public function ownAccountLabel(string $value): ?string
    {
        foreach ($this->all() as $preset) {
            if ($preset->ownAccountIdentifier() === $value) {
                return $preset->label;
            }
        }

        return null;
    }

    /**
     * @return array<string, CsvPreset>
     */
    private function build(): array
    {
        $presets = [
            new CsvPreset(
                format: self::N26,
                label: 'N26',
                headerSignature: ['Booking Date', 'Partner Name', 'Amount (EUR)'],
                dateHeader: 'Booking Date',
                dateFormat: 'Y-m-d',
                amountStrategy: CsvPreset::SIGNED,
                decimalSeparator: '.',
                delimiter: ',',
                descriptionHeaders: ['Payment Reference'],
                amountHeader: 'Amount (EUR)',
                valueDateHeader: 'Value Date',
                counterpartyNameHeader: 'Partner Name',
                counterpartyIbanHeader: 'Partner Iban',
                fixedCurrency: Currency::Eur->value,
            ),

            new CsvPreset(
                format: self::REVOLUT,
                label: 'Revolut',
                headerSignature: ['Type', 'Started Date', 'Completed Date', 'Amount', 'Currency', 'State'],
                dateHeader: 'Completed Date',
                dateFormat: 'Y-m-d H:i:s',
                amountStrategy: CsvPreset::SIGNED,
                decimalSeparator: '.',
                delimiter: ',',
                amountHeader: 'Amount',
                // Description is the counterparty, not a payment reference: it
                // is the export's only text column and it holds the merchant or
                // the person. Read as a description it left every row nameless.
                counterpartyNameHeader: 'Description',
                currencyHeader: 'Currency',
                feeHeader: 'Fee',
                stateHeader: 'State',
                // The one state whose row the account actually moved by. A
                // REVERTED charge still carries its full Amount, so admitting
                // it would book a payment the bank had already given back.
                acceptedStates: ['COMPLETED'],
            ),

            new CsvPreset(
                format: self::ING_NL,
                label: 'ING (Netherlands)',
                headerSignature: ['Datum', 'Naam/Omschrijving', 'Af Bij', 'Bedrag (EUR)'],
                dateHeader: 'Datum',
                dateFormat: 'Ymd',
                amountStrategy: CsvPreset::INDICATOR,
                decimalSeparator: ',',
                delimiter: ',',
                descriptionHeaders: ['Mededelingen'],
                amountHeader: 'Bedrag (EUR)',
                indicatorHeader: 'Af Bij',
                debitIndicator: 'Af',
                creditIndicator: 'Bij',
                counterpartyNameHeader: 'Naam/Omschrijving',
                counterpartyIbanHeader: 'Tegenrekening',
                ownIbanHeader: 'Rekening',
                fixedCurrency: Currency::Eur->value,
            ),
        ];

        $keyed = [];
        foreach ($presets as $preset) {
            $keyed[$preset->format] = $preset;
        }

        return $keyed;
    }

    /**
     * @return array<string, PositionalCsvPreset>
     */
    private function buildPositional(): array
    {
        $presets = [
            new PositionalCsvPreset(
                format: self::ASN,
                label: 'ASN',
                headerSignature: ['Datum', 'Je rekening'],
                acceptedColumnCounts: [19, 20],
                dateFormat: 'd-m-Y',
                postedDateColumn: 0,
                ownIbanColumn: 1,
                amountColumn: 10,
                valueDateColumn: 12,
                currencyColumn: 9,
                descriptionColumns: [16, 17],
                counterpartyIbanColumn: 2,
                counterpartyNameColumn: 3,
                sourceRefColumn: 15,
            ),
        ];

        $keyed = [];
        foreach ($presets as $preset) {
            $keyed[$preset->format] = $preset;
        }

        return $keyed;
    }
}
