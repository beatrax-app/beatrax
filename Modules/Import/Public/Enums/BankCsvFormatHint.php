<?php

declare(strict_types=1);

namespace Modules\Import\Public\Enums;

use Modules\Ingestion\Public\Enums\SourceFormat;

// Every bank exports its own CSV column shape, so a CSV import has to declare
// which dialect it is rather than be sniffed. A preset names its own dialect in
// its format id, which leaves only the built-in ASN CSV needing to say so here.
enum BankCsvFormatHint: string
{
    case Asn = SourceFormat::AsnCsv->value;
}
