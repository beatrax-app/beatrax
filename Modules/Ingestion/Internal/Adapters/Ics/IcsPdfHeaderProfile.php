<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Modules\Core\Public\Support\UploadLimits;

/**
 * @link ../../../../../.docs/features/ingestion/architecture.md
 */
final class IcsPdfHeaderProfile
{
    public const FORMAT = 'ics-pdf';

    // Verified against the upload's first five bytes to reject a non-PDF
    // file before pdftotext is invoked.
    public const MIME_MAGIC = '%PDF-';

    public const int MAX_BYTES = UploadLimits::MAX_BYTES;

    public const SOURCE_ENCODING = 'UTF-8';

    // The Mijn ICS consumer portal always settles in EUR, regardless of
    // whether the underlying transaction is native EUR or foreign-currency
    // (foreign rows carry an inline Wisselkoers conversion to EUR).
    public const STATEMENT_CURRENCY = 'EUR';
}
