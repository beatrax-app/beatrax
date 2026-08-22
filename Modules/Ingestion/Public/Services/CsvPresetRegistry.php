<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Services;

use Modules\Ingestion\Public\Dto\CsvPreset;
use Modules\Ledger\Public\Enums\Currency;

final class CsvPresetRegistry
{
    public const ISSUER = 'other-bank';

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

    /**
     * @return array<string, CsvPreset>
     */
    private function build(): array
    {
        $presets = [
            new CsvPreset(
                format: 'n26-csv',
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
            new CsvPreset(
                format: 'revolut-csv',
                label: 'Revolut',
                issuer: self::ISSUER,
                headerSignature: ['Type', 'Started Date', 'Completed Date', 'Amount', 'Currency', 'State'],
                dateHeader: 'Completed Date',
                dateFormat: 'Y-m-d H:i:s',
                amountStrategy: CsvPreset::SIGNED,
                decimalSeparator: '.',
                delimiter: ',',
                descriptionHeaders: ['Description'],
                amountHeader: 'Amount',
                currencyHeader: 'Currency',
                feeHeader: 'Fee',
                stateHeader: 'State',
                acceptedStates: ['COMPLETED'],
            ),

            new CsvPreset(
                format: 'ing-nl-csv',
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
