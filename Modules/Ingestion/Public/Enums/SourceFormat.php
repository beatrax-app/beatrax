<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Enums;

// The code-pinned bank-statement `source_format` values. `asn-csv` / `camt053`
// / `mt940` also key the adapter registry; `ing-csv` rides the ASN CSV adapter
// as a layout. CSV presets add further formats at runtime, and ics-pdf /
// paypal-csv / receipt formats are separate vocabularies — this names only these.
enum SourceFormat: string
{
    case AsnCsv = 'asn-csv';

    case IngCsv = 'ing-csv';

    case Camt053 = 'camt053';

    case Mt940 = 'mt940';
}
