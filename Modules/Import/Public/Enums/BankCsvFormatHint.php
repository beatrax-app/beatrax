<?php

declare(strict_types=1);

namespace Modules\Import\Public\Enums;

use Modules\Ingestion\Public\Enums\SourceFormat;

// CSV is the only ambiguous bank-statement format (every bank exports
// its own column shape), so the user picks the source bank up front
// and the chosen case is what the pipeline dispatches against instead
// of sniffing the file.
enum BankCsvFormatHint: string
{
    case Asn = SourceFormat::AsnCsv->value;
    case Ing = SourceFormat::IngCsv->value;

    public function adapterFormatKey(): string
    {
        return $this->value;
    }

    public function displayName(): string
    {
        return match ($this) {
            self::Asn => 'ASN',
            self::Ing => 'ING',
        };
    }
}
