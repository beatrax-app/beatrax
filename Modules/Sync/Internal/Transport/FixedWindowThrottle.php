<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Modules\Core\Public\Enums\Duration;

// The one window every sync rate limiter counts inside, so a pairing offer and
// a relay frame are throttled against the same clock rather than each carrying
// its own number. PairingOfferRateLimiter and RelayRateLimiter are its callers.
final class FixedWindowThrottle
{
    public static function windowSeconds(): int
    {
        return Duration::Minute->seconds();
    }
}
