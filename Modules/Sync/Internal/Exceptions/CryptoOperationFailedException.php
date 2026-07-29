<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;
use Throwable;

// A cryptographic primitive did not behave as documented. Every site that
// raises this wraps a SodiumException from a call whose inputs it has
// already validated, so reaching it means the library or the build is
// wrong rather than the data — which is why none of these are retried.
final class CryptoOperationFailedException extends RuntimeException
{
    public static function during(string $operation, ?Throwable $previous = null): self
    {
        return new self("Sync crypto failed during {$operation}.", 0, $previous);
    }
}
