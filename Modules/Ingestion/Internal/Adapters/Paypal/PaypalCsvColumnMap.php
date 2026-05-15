<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

/**
 * Language-keyed lookup from canonical column names (`date`, `type`,
 * `currency`, `gross`, `transactionId`, `referenceTxnId`, ...) to the
 * verbatim header cells of the PayPal Activity Download CSV.
 *
 * The map sits alongside `PaypalCsvLanguageProfile` rather than being
 * folded into it because the LanguageProfile owns the header-detection
 * signature (a discriminator subset), while this map owns the full
 * canonical-name → header-cell table — two distinct responsibilities.
 *
 * Empirical column shape locked in `paypal-sample-1.md` "Empirical
 * column layout" section. Wave 0 surfaced an NL-locale export only; an
 * EN-locale entry lands the moment a second-language sample appears.
 *
 * `counterpartyIban` maps to the `Bankrekening` column, which the Wave 0
 * fixture surfaced as empty on every row (the funding-source child rows
 * carry no IBAN). Resolving the cell still returns the empty string,
 * matching the column shape — the rollup walker is responsible for
 * promoting that to `null` before constructing the SourceTransactionDto.
 */
final class PaypalCsvColumnMap
{
    /**
     * Canonical name → verbatim header column, keyed by language code.
     *
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
     * Resolves a canonical column name against a row in the detected
     * language. Returns the trimmed cell value, or null when the
     * canonical column is not mapped for that language OR the row does
     * not carry that header.
     *
     * Trimming defends two empirical PayPal quirks at once:
     *   - The `Bruto ` and `Kosten ` headers ship with a trailing space
     *     inside their quoted cells; this map lists them WITHOUT the
     *     trailing space, and the lookup tries both spellings.
     *   - Per-cell values can be surrounded by spurious whitespace
     *     (rare but observed in some PayPal exports).
     *
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
