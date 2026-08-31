<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers\Csv;

use Modules\Import\Internal\Parsers\DutchNarrativeHinter;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;

// Bound to the positional CSV preset by its format id, so the issuer's name
// reaches this class as data and never as an identifier.
final class PositionalCsvPaymentTypeHinter extends DutchNarrativeHinter
{
    protected const SOURCE_FORMAT = CsvPresetRegistry::ASN;
}
