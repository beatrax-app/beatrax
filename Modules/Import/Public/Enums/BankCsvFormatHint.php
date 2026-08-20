<?php

declare(strict_types=1);

namespace Modules\Import\Public\Enums;

use Modules\Ingestion\Public\Enums\SourceFormat;

// Every bank exports its own CSV column shape, so the user picks the bank
// up front and the pipeline dispatches on the choice rather than sniffing.
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
