<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

/**
 * @link ../../../../../.docs/features/ingestion/architecture.md
 */
final class IcsPdfHeaderProfile
{
    public const FORMAT = 'ics-pdf';

    // Verified against the upload's first five bytes to reject a non-PDF
    // file before pdftotext is invoked.
    public const MIME_MAGIC = '%PDF-';

    // 10 MiB, matching the wizard's max:10240 (KB) upload rule so an
    // over-sized file is rejected consistently at both boundaries.
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const SOURCE_ENCODING = 'UTF-8';

    // The Mijn ICS consumer portal always settles in EUR, regardless of
    // whether the underlying transaction is native EUR or foreign-currency
    // (foreign rows carry an inline Wisselkoers conversion to EUR).
    public const STATEMENT_CURRENCY = 'EUR';
}
