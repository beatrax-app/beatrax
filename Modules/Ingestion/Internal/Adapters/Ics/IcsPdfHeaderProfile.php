<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Modules\Core\Public\Support\UploadLimits;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Public\Enums\Currency;

final class IcsPdfHeaderProfile
{
    public const FORMAT = SourceFormat::IcsPdf->value;

    public const string MIME_MAGIC = '%PDF-';

    public const int MAX_BYTES = UploadLimits::MAX_BYTES;

    public const string SOURCE_ENCODING = 'UTF-8';

    // Mijn ICS always settles in EUR; a foreign-currency row carries an
    // inline Wisselkoers conversion rather than settling natively.
    public const STATEMENT_CURRENCY = Currency::Eur->value;
}
