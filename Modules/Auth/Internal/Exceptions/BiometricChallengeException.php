<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Exceptions;

use RuntimeException;

// The pending creation challenge was absent or undecodable. Distinct because
// it means a broken or replayed handshake, not bad input.
final class BiometricChallengeException extends RuntimeException
{
    public static function missing(): self
    {
        return new self('No pending creation challenge in session.');
    }

    public static function malformedEncoding(): self
    {
        return new self('Invalid creation challenge encoding.');
    }
}
