<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when the PayPal CSV file the user uploaded matches a recognised
 * PayPal export shape that this app does NOT consume — for example, the
 * Balance Reconciliation Report (`RH` / `RD` / `RF` row-type prefixes)
 * rather than the per-event Activity Download.
 *
 * The two PayPal exports look superficially similar (both `.csv`,
 * comma-delimited, UTF-8 with BOM) but ship completely different column
 * shapes. Without a shape check the sniffer falls through to the
 * language-profile check and surfaces the generic "language profile not
 * supported" message, which is misleading — the file is in Dutch, but
 * it's the wrong export type.
 *
 * Message text is user-facing — the upload wizard renders it verbatim
 * so the user knows which export to download from the PayPal portal.
 */
final class UnsupportedPaypalCsvShapeException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
