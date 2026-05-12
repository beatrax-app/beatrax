<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;

/**
 * Thrown by SourceAdapterRegistry when no adapter is registered for the
 * declared format string (e.g. 'asn-mt940' while only 'asn-csv' is wired).
 */
final class UnsupportedFormatException extends RuntimeException {}
