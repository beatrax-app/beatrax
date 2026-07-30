<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;

// The cipher state's 64-bit nonce is approaching its ceiling. Not a failure
// of anything: it is the point at which continuing would eventually repeat a
// nonce under the same key, which is the one thing that breaks the AEAD
// outright. The session has to rekey rather than retry.
final class NoiseNonceExhaustedException extends RuntimeException
{
    public static function beforeRekey(): self
    {
        return new self(
            'Noise nonce overflow — nonce approaches PHP_INT_MAX. Initiate a rekey before this point.',
        );
    }
}
