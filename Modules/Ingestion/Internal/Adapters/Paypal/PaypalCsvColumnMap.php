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
        $header = self::COLUMNS[$language][$canonical] ?? null;
        if ($header === null) {
            return null;
        }

        // PayPal's NL export ships `"Bruto "` and `"Kosten "` with a
        // trailing space INSIDE the quoted token, so the trailing-space
        // variant is consulted as a fallback before giving up — both shapes
        // resolve to the same canonical column.
        $raw = $row[$header] ?? $row[$header.' '] ?? null;

        return $raw === null ? null : trim($raw);
    }
}
