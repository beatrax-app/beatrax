<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

final class PaypalCsvColumnMap
{
    /**
     * @var array<string, array<string, string>>
     */
    private const array COLUMNS = [
        'nl' => [
            'date' => 'Datum',
            'time' => 'Tijd',
            'timezone' => 'Tijdzone',
            'type' => 'Omschrijving',
            'currency' => 'Valuta',
            'gross' => 'Bruto',
            'fee' => 'Kosten',
            'net' => 'Netto',
            'balance' => 'Saldo',
            'transactionId' => 'Transactiereferentie',
            'counterpartyEmail' => 'Van e-mailadres',
            'counterpartyName' => 'Naam',
            'counterpartyBankName' => 'Naam bank',
            'counterpartyIban' => 'Bankrekening',
            'shippingFee' => 'Verzendkosten',
            'vat' => 'Btw',
            'invoiceRef' => 'Factuurreferentie',
            'referenceTxnId' => 'Reference Txn ID',
        ],
    ];

    // The column's own name, for the one caller that needs it rather than a
    // row's value in it: PayPal states its fee as a column on the payment row,
    // and the row the rollup derives from that cell is described by the header
    // the figure came out of, in whichever language the file is written in.
    public function header(string $canonical, string $language): ?string
    {
        return self::COLUMNS[$language][$canonical] ?? null;
    }

    /**
     * @param  array<string, string>  $row  league/csv associative record
     */
    public function value(string $canonical, string $language, array $row): ?string
    {
        $header = self::COLUMNS[$language][$canonical] ?? null;
        if ($header === null) {
            return null;
        }

        // PayPal's NL export ships `"Bruto "` and `"Kosten "` with a trailing space
        // INSIDE the quoted token, so both spellings resolve to one canonical column.
        $raw = $row[$header] ?? $row[$header.' '] ?? null;

        return $raw === null ? null : trim($raw);
    }
}
