<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;

// Thrown by PdfTextExtractor for an unreadable input, a non-.pdf suffix, an
// over-size upload, or a missing/failing pdftotext binary; messages are
// user-facing-safe and render verbatim in the preview screen's ERROR row.
final class PdfExtractionFailed extends RuntimeException {}
