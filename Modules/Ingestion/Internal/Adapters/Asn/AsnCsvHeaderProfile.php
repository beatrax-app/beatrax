<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn;

/**
 * CSV profile for the ASN Online Bankieren "CSV met IBAN" export.
 *
 * The values reflect what `tests/fixtures/asn-sample-1.csv` actually
 * contains — a real anonymised 2026 export — and what `file -bI` reports
 * for the committed fixture. The full header-cell map lives next to the
 * fixture at `tests/fixtures/asn-sample-1.md`.
 */
final class AsnCsvHeaderProfile
{
    public const FORMAT = 'asn-csv';

    public const DELIMITER = ',';

    public const HAS_HEADER = true;

    public const SOURCE_ENCODING = 'UTF-8';

    public const EXPECTED_COLUMN_COUNT = 20;

    /**
     * Column counts the sniffer accepts. ASN exports the trailing
     * `Categorie` column inconsistently — some accounts ship 20 columns
     * (the documented layout) while others ship only 19, omitting
     * `Categorie`. Both are valid ASN CSVs; the adapter never reads
     * `Categorie` or `Afschriftnummer` (indices 18 and 19) so accepting
     * either shape is safe. Older 17/18-column variants are intentionally
     * rejected — those predate `Afschriftnummer` and the adapter cannot
     * resolve `Volgnummer` (column 15) deterministically against them.
     *
     * @var list<int>
     */
    public const ACCEPTED_COLUMN_COUNTS = [19, 20];

    /**
     * The first two header cells. Used by HeaderSniffer to distinguish the
     * ASN CSV from any other comma-delimited file in the accepted column
     * range. Locked to the empirical NL header text; if ASN renames either
     * column the sniffer fails loudly.
     */
    public const HEADER_SIGNATURE = ['Datum', 'Je rekening'];

    /**
     * Date format used in every date column of the export (Datum,
     * Verwerkingsdatum, Valutadatum). `CarbonImmutable::createFromFormat`
     * consumers should prefix with `!` to zero out the time portion.
     */
    public const DATE_FORMAT = 'd-m-Y';
}
