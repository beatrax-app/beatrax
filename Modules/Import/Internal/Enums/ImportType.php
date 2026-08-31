<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Enums;

use Modules\Core\Public\Support\Lang;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;

// The reader picks the shape of the file they hold, not the institution that
// issued it: which banks are covered is answered on the website, and a bank's
// own name lives in its CSV preset as data.
enum ImportType: string
{
    case Csv = 'csv';

    case Camt053 = 'camt053';

    case Mt940 = 'mt940';

    case Pdf = 'pdf';

    case Email = 'email';

    /**
     * @return list<string> the source formats this type accepts, first one first
     */
    public function formats(): array
    {
        return match ($this) {
            self::Csv => [
                CsvPresetRegistry::ASN,
                CsvPresetRegistry::ING_NL,
                CsvPresetRegistry::N26,
                CsvPresetRegistry::REVOLUT,
                SourceFormat::PaypalCsv->value,
            ],
            self::Camt053 => [SourceFormat::Camt053->value],
            self::Mt940 => [SourceFormat::Mt940->value],
            self::Pdf => [SourceFormat::IcsPdf->value],
            self::Email => SourceFormat::receiptFormats(),
        };
    }

    public function label(): string
    {
        return Lang::get('import::upload.types.'.$this->value);
    }
}
