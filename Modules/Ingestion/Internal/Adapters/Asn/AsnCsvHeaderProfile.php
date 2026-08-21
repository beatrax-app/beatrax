<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn;

use Modules\Ingestion\Public\Enums\SourceFormat;

final class AsnCsvHeaderProfile
{
    public const FORMAT = SourceFormat::AsnCsv->value;

    public const DELIMITER = ',';

    public const HAS_HEADER = true;

    public const SOURCE_ENCODING = 'UTF-8';

    public const EXPECTED_COLUMN_COUNT = 20;

    /**
     * @var list<int>
     */
    public const ACCEPTED_COLUMN_COUNTS = [19, 20];

    public const HEADER_SIGNATURE = ['Datum', 'Je rekening'];

    // Every date column (Datum, Verwerkingsdatum, Valutadatum) uses this
    // format; prefix with `!` in createFromFormat() to zero the time part.
    public const DATE_FORMAT = 'd-m-Y';
}
