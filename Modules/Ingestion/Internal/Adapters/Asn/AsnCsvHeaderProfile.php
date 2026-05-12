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
     * The first two header cells. Used by HeaderSniffer to distinguish the
     * ASN CSV from any other 20-column comma file. Locked to the empirical
     * NL header text; if ASN renames either column the sniffer fails loudly.
     */
    public const HEADER_SIGNATURE = ['Datum', 'Je rekening'];

    /**
     * Date format used in every date column of the export (Datum,
     * Verwerkingsdatum, Valutadatum). `CarbonImmutable::createFromFormat`
     * consumers should prefix with `!` to zero out the time portion.
     */
    public const DATE_FORMAT = 'd-m-Y';
}
