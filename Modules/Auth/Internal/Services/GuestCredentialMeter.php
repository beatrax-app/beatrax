<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Services;

use Illuminate\Cache\RateLimiter;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Public\Enums\Duration;

// Two credentials an unauthenticated caller can try against one account, and
// one way of counting them. They are counted apart, because spending one must
// not spend the other, but a second copy of these four calls is where the two
// would drift into disagreeing about the window or the key.
abstract readonly class GuestCredentialMeter
{
    public function __construct(private RateLimiter $limiter) {}

    public function isExhausted(string $usernameInput): bool
    {
        return $this->limiter->tooManyAttempts($this->key($usernameInput), GuestAttemptCap::PER_MINUTE);
    }

    public function recordAttempt(string $usernameInput): void
    {
        $this->limiter->hit($this->key($usernameInput), Duration::Minute->seconds());
    }

    public function clear(string $usernameInput): void
    {
        $this->limiter->clear($this->key($usernameInput));
    }

    public function availableIn(string $usernameInput): int
    {
        return $this->limiter->availableIn($this->key($usernameInput));
    }

    abstract protected function keyPrefix(): string;

    // Keyed on what the caller typed, normalised, so an unknown username is
    // metered exactly like a known one. A key that existed only for real
    // accounts would answer in throttling the question the constant failure
    // message and the equalised hash both refuse to answer in words.
    private function key(string $usernameInput): string
    {
        return $this->keyPrefix().Username::normalize($usernameInput);
    }
}
