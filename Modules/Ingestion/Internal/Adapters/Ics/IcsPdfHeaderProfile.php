<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

/**
 * Mijn ICS consumer-portal monthly-statement PDF profile.
 *
 * The FORMAT constant is the stable identifier the SourceAdapterRegistry,
 * the upload wizard dropdown, and HeaderSniffer reference.
 */
final class IcsPdfHeaderProfile
{
    public const FORMAT = 'ics-pdf';

    /**
     * Magic-byte prefix every PDF file begins with. The HeaderSniffer
     * verifies this against the first five bytes of the upload to reject
     * a non-PDF file before pdftotext is invoked.
     */
    public const MIME_MAGIC = '%PDF-';

    /** 10 MiB — matches the wizard's `max:10240` (KB) upload rule. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** UTF-8 is the encoding pdftotext emits with `-enc UTF-8`. */
    public const SOURCE_ENCODING = 'UTF-8';
}
