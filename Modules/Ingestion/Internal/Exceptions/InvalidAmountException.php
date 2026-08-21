<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Exceptions;

use RuntimeException;

// The message carries the offending raw cell so the upload wizard can surface it.
final class InvalidAmountException extends RuntimeException {}
