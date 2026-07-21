<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use RuntimeException;

// Thrown when a provider responds with HTTP 429 / 403 rateLimitExceeded.
// retryAfterSeconds carries the provider-suggested back-off so the
// caller (a queued job) can sleep / re-dispatch with the correct delay
// instead of the worker's default exponential back-off.
final class RateLimitedException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds = 60,
        string $message = 'Provider rate limit exceeded.',
    ) {
        parent::__construct($message);
    }
}
