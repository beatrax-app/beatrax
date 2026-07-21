<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;

// Kept distinct from InvalidAmountException so grep on date-related
// failures does not land in amount-parser stack traces.
final class InvalidDateException extends RuntimeException {}
