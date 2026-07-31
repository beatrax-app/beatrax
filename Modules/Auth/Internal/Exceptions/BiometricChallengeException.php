<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Exceptions;

use RuntimeException;

// The pending creation challenge could not be pulled back from the session
// during enrollment, either absent or not decodable. Distinct because it
// signals a broken or replayed ceremony handshake, not a validation error
// over otherwise well-formed input.
/**
 * @link ../../../../.docs/features/auth/architecture.md
 */
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
