<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn;

/**
 * Empirical CSV profile for the ASN Online Bankieren "CSV met IBAN" export.
 *
 * The values reflect what `tests/fixtures/asn-sample-1.csv` actually contains
 * — a real anonymized 2026 export. The audit trail (delimiter, encoding,
 * header presence, column count, full header map) lives next to the fixture
 * at `tests/fixtures/asn-sample-1.md`.
 *
 * Three values had to be corrected away from earlier community-reported
 * shapes:
 *   - HAS_HEADER = true (2026 export ships a header row)
 *   - SOURCE_ENCODING = UTF-8 (file -bI on the committed fixture)
 *   - EXPECTED_COLUMN_COUNT = 20 (Afschriftnummer + Categorie added at the tail)
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
