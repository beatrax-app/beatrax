<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;

/**
 * Thrown when a source date cell cannot be parsed into a CarbonImmutable
 * by any of the registered locale formats. Distinct from
 * `InvalidAmountException` so grep on date-related failures does not
 * land in amount-parser stack traces.
 */
final class InvalidDateException extends RuntimeException {}
