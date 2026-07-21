<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;

// Thrown when a source-format amount cell cannot be parsed into integer
// minor units; the message carries the offending raw string so the upload
// wizard can surface it back to the user.
final class InvalidAmountException extends RuntimeException {}
