<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Exceptions;

use RuntimeException;

// Messages are user-facing-safe and render verbatim in the preview screen's ERROR row.
final class PdfExtractionFailed extends RuntimeException {}
