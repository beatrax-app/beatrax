<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;

// The relay was reached and answered with something other than success. The
// relay is a dumb store-and-forward hop that holds ciphertext it cannot read,
// so a bad answer says nothing about the payload — it is worth retrying, which
// is what separates this from RelayRefusedException.
final class RelayUnavailableException extends RuntimeException
{
    public static function requestFailed(string $operation, int $status, string $endpoint): self
    {
        return new self("Relay {$operation} failed: HTTP {$status} from {$endpoint}");
    }
}
