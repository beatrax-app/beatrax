<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Exceptions;

use RuntimeException;

// A browser payload or key-wrap blob that fails the shape the ceremony
// requires. Its own type so the enroll endpoint reports an enrollment fault
// rather than an anonymous one.
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
