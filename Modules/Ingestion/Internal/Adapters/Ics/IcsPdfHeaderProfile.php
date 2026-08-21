<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Modules\Core\Public\Support\UploadLimits;

final class IcsPdfHeaderProfile
{
    public const FORMAT = 'ics-pdf';

    public const MIME_MAGIC = '%PDF-';

    public const int MAX_BYTES = UploadLimits::MAX_BYTES;

    public const SOURCE_ENCODING = 'UTF-8';

    // Mijn ICS always settles in EUR; a foreign-currency row carries an
    // inline Wisselkoers conversion rather than settling natively.
    public const STATEMENT_CURRENCY = 'EUR';
}
