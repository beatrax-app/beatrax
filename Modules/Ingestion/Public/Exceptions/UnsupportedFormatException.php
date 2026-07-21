<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;

// Thrown by SourceAdapterRegistry when no adapter is registered for the
// declared format string.
final class UnsupportedFormatException extends RuntimeException {}
