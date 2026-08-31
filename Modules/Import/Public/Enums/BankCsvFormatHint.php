<?php

declare(strict_types=1);

namespace Modules\Import\Public\Enums;

use Modules\Ingestion\Public\Services\CsvPresetRegistry;

// Vestigial: every CSV dialect is now a CsvPresetRegistry preset whose format id
// already names it, so this carries nothing the id does not. It survives only
// because its one case is named by test call sites in modules outside Import.
enum BankCsvFormatHint: string
{
    case Asn = CsvPresetRegistry::ASN;
}
