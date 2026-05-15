<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;

/**
 * Thrown by `PdfTextExtractor` when the input cannot be extracted to text:
 *
 *   - the input path does not exist or is not readable;
 *   - the input does not have a .pdf suffix (defence-in-depth against
 *     callers that bypass the upload-wizard's HeaderSniffer);
 *   - the input exceeds the 10 MiB size cap;
 *   - the pdftotext binary is missing or returns a non-zero exit code.
 *
 * Message strings are user-facing-safe — the wizard renders them verbatim
 * in the ERROR row of the preview screen.
 */
final class PdfExtractionFailed extends RuntimeException {}
