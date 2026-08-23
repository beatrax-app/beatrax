<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Services;

use Modules\Ingestion\Public\Dto\CsvPreset;
use Modules\Ledger\Public\Enums\Currency;

final class CsvPresetRegistry
{
    public const string ISSUER = 'other-bank';

    // A preset id is the adapter key the registry binds and the value every
    // picker submits, so it is named here rather than spelled out at each of
    // those call sites.
    public const string N26 = 'n26-csv';

    public const string REVOLUT = 'revolut-csv';

    public const string ING_NL = 'ing-nl-csv';

    /** @var array<string, CsvPreset>|null */
    private ?array $presets = null;

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

    public function has(string $format): bool
    {
        return isset($this->all()[$format]);
    }

    // True when this is a placeholder a preset issued because its export
    // carries no own-IBAN column, and so must not be held to an IBAN's shape.
    public function issuesOwnAccountIdentifier(string $value): bool
    {
        foreach ($this->all() as $preset) {
            if ($preset->ownAccountIdentifier() === $value) {
                return true;
            }
        }

        return false;
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
                issuer: self::ISSUER,
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

            // A pending Revolut row ships an empty Completed Date; the adapter's
            // no-booking-date branch skips it before acceptedStates is consulted.
            //
            // Description is the counterparty, not a payment reference: it is
            // the export's only text column and it holds the merchant or the
            // person. Read as a description it left every row nameless.
            new CsvPreset(
                format: self::REVOLUT,
                label: 'Revolut',
                issuer: self::ISSUER,
                headerSignature: ['Type', 'Started Date', 'Completed Date', 'Amount', 'Currency', 'State'],
                dateHeader: 'Completed Date',
                dateFormat: 'Y-m-d H:i:s',
                amountStrategy: CsvPreset::SIGNED,
                decimalSeparator: '.',
                delimiter: ',',
                amountHeader: 'Amount',
                counterpartyNameHeader: 'Description',
                currencyHeader: 'Currency',
                feeHeader: 'Fee',
                stateHeader: 'State',
                acceptedStates: ['COMPLETED'],
            ),

            new CsvPreset(
                format: self::ING_NL,
                label: 'ING (Netherlands)',
                issuer: self::ISSUER,
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
}
