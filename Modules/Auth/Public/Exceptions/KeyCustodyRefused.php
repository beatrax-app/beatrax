<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Exceptions;

use RuntimeException;

// Raised where the platform key store is present and refuses the write. Custody
// fails closed rather than hand the raw key back to a caller that persists it:
// what store() returns lands in the session payload, which outlives the process
// on every shape this app ships.

// Not final, and shared by both shells on purpose. The desktop and the mobile
// custodian answered this same refusal differently for a release -- one threw,
// one returned the key -- and one catchable type is what stops that recurring.
class KeyCustodyRefused extends RuntimeException
{
    public static function writeRefused(string $custodian, string $store): self
    {
        return new self(sprintf(
            '%s: %s is present and refused the write; refusing to hand back the raw key.',
            $custodian,
            $store,
        ));
    }
}
