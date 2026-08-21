<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Exceptions;

use RuntimeException;

// The serializer factory returned something other than the concrete Serializer
// this service needs. Its own type keeps a library-contract break separable
// from the ceremony failures it sits beside.
final class WebAuthnSerializerException extends RuntimeException
{
    public static function unexpectedType(): self
    {
        return new self('Unexpected serializer type.');
    }
}
