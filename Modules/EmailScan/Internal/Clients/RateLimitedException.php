<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use RuntimeException;

// HTTP 429 / 403 rateLimitExceeded. The provider-suggested back-off is carried
// so the queued caller re-dispatches on it instead of the worker's own
// exponential default.
final class RateLimitedException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds = 60,
        string $message = 'Provider rate limit exceeded.',
    ) {
        parent::__construct($message);
    }
}
