<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;

// The PDF opened and its pages were read, and they carry no text at all — a
// scan or a photograph of a statement rather than a statement. Public because
// the import screen has to tell it apart from a reader that could not run and
// from a statement whose columns simply did not match.
final class PdfHasNoTextLayerException extends RuntimeException {}
