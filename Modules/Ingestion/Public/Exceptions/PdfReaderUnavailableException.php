<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;

// The PDF text extractor this install would have shelled out to is not there.
// Public because it is the one PDF failure the reader can act on, and acting on
// it means the import screen has to be able to tell it apart from a file that
// is merely the wrong shape.
final class PdfReaderUnavailableException extends RuntimeException {}
