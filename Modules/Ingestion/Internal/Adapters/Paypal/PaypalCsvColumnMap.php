<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

/**
 * @link ../../../../../.docs/features/ingestion/architecture.md
 */
final class PaypalCsvColumnMap
{
    /**
     * @var array<string, array<string, string>>
     */
    private const COLUMNS = [
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

    /**
     * @param  array<string, string>  $row  league/csv associative record
     */
    public function value(string $canonical, string $language, array $row): ?string
    {
        if (! isset(self::COLUMNS[$language][$canonical])) {
            return null;
        }

        $header = self::COLUMNS[$language][$canonical];
        if (array_key_exists($header, $row)) {
            return trim($row[$header]);
        }

        // PayPal's NL export ships `"Bruto "` and `"Kosten "` with a
        // trailing space INSIDE the quoted token. Try the trailing-space
        // variant before giving up so the parser tolerates both shapes.
        $headerWithTrailingSpace = $header.' ';
        if (array_key_exists($headerWithTrailingSpace, $row)) {
            return trim($row[$headerWithTrailingSpace]);
        }

        return null;
    }
}
