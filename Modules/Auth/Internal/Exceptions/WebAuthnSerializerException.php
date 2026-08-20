<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Exceptions;

use RuntimeException;

// The Webauthn serializer factory returned a type that is not the concrete
// Serializer this service relies on for both normalize() and deserialize().
// A dedicated type keeps this library-contract invariant separable from the
// runtime ceremony failures it sits beside.
final class WebAuthnSerializerException extends RuntimeException
{
    public static function unexpectedType(): self
    {
        return new self('Unexpected serializer type.');
    }
}
