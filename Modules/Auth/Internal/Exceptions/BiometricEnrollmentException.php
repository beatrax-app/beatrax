<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Exceptions;

use RuntimeException;

// Raised while completing a WebAuthn enrollment when the browser payload or
// the produced key-wrap bytes fail the shape the ceremony requires. Worth
// its own type so the enroll endpoint's catch reports an enrollment fault
// rather than swallowing an anonymous generic error.
final class BiometricEnrollmentException extends RuntimeException
{
    public static function unexpectedAttestationResponse(): self
    {
        return new self('Expected attestation response.');
    }

    public static function keyWrapEncodingFailed(): self
    {
        return new self('Wrap produced invalid base64.');
    }
}
