<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Services;

use Modules\Ingestion\Public\Dto\CsvPreset;

/**
 * The bundled set of bank/fintech CSV presets that drive GenericCsvAdapter.
 * Each preset is registered as its own ingestion format; this registry is
 * the single source of truth consumed by the service provider (to register
 * one adapter per preset), the HeaderSniffer (to validate a declared
 * preset's header signature), and the upload wizard (to list the choices).
 *
 * Adding a bank = adding a preset here. Column specs are from each
 * provider's documented export format; verify against a real export before
 * trusting the exact header strings.
 */
final class CsvPresetRegistry
{
    /** Issuer key the upload wizard groups these presets under. */
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
            // N26 (EN export): signed Amount (EUR), ISO dates, clean IBAN/name.
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
                fixedCurrency: 'EUR',
            ),

            // Revolut (personal export): signed Amount, per-row Currency,
            // datetime Completed Date (the settlement/booking date). Only
            // COMPLETED rows are imported — PENDING/REVERTED/DECLINED rows are
            // skipped so reversed transactions aren't double-counted and
            // pending rows (empty Completed Date) don't abort the import.
            // Counterparty lives in Description.
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

            // ING Netherlands ("Kommagescheiden CSV"): YYYYMMDD date, comma
            // decimal, unsigned Bedrag with an "Af"/"Bij" direction column.
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
                fixedCurrency: 'EUR',
            ),
        ];

        $keyed = [];
        foreach ($presets as $preset) {
            $keyed[$preset->format] = $preset;
        }

        return $keyed;
    }
}
