<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Exceptions;

use RuntimeException;

// A request refused before it was sent, because attaching the bearer token
// would hand it somewhere it does not belong — a non-HTTPS scheme, or a host
// outside the Enable Banking API and the bank's own SCA origin. Kept apart
// from EnableBankingApiException because no retry is ever appropriate here.
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
