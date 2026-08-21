<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Exceptions;

use RuntimeException;

// Refused before sending, because the bearer token would go somewhere outside
// the API host and the bank's SCA origin. Never retryable.
final class UnsafeOpenBankingRequestException extends RuntimeException
{
    public static function nonHttpsScheme(): self
    {
        return new self('Refusing to send an Enable Banking bearer token over a non-HTTPS scheme.');
    }

    public static function disallowedHost(?string $host): self
    {
        $named = $host !== null && $host !== '' ? $host : '(unparseable)';

        return new self("Refusing to send an Enable Banking bearer token to non-allow-listed host: {$named}");
    }
}
