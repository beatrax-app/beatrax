<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn;

/**
 * Column index map for the ASN Online Bankieren "CSV met IBAN" export.
 *
 * Reflects the 20-column shape committed in
 * `tests/fixtures/asn-sample-1.csv`. The full header → field mapping
 * lives next to the fixture at `tests/fixtures/asn-sample-1.md`; keep
 * the two files in sync if ASN ever changes the export layout.
 */
final class AsnCsvColumnMap
{
    public const POSTED_DATE = 0;          // Datum                 — dd-mm-yyyy

    public const OWN_IBAN = 1;             // Je rekening

    public const COUNTERPARTY_IBAN = 2;    // Van / naar

    public const COUNTERPARTY_NAME = 3;    // Naam

    public const COUNTERPARTY_ADDR = 4;    // Adres

    public const COUNTERPARTY_POSTAL = 5;  // Postcode

    public const COUNTERPARTY_CITY = 6;    // Woonplaats

    public const ACCOUNT_CURRENCY = 7;     // Valuta saldo

    public const SALDO_BEFORE = 8;         // Saldo voor boeking

    public const MUTATION_CURRENCY = 9;    // Valuta

    public const AMOUNT = 10;              // Bedrag bij / af       — signed period-decimal

    public const JOURNAL_DATE = 11;        // Verwerkingsdatum

    public const VALUE_DATE = 12;          // Valutadatum

    public const INTERNAL_TX_CODE = 13;    // Code

    public const GLOBAL_TX_CODE = 14;      // Type

    public const SEQUENCE_NUMBER = 15;     // Volgnummer            → source_ref

    public const PAYMENT_REF = 16;         // Betalingskenmerk

    public const DESCRIPTION = 17;         // Omschrijving          — may contain literal \r

    public const STATEMENT_NUMBER = 18;    // Afschriftnummer

    public const CATEGORY = 19;            // Categorie             — ASN-side label; typically empty
}
