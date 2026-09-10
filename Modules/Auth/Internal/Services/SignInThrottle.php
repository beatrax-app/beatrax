<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Services;

use Illuminate\Cache\RateLimiter;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Public\Enums\Duration;

// The meter both sign-in paths share. LoginAction serves the Livewire form and
// Fortify's own pipeline serves a post that arrived before Livewire booted, so
// a limit written into one of them is a limit the other walks around.
final readonly class SignInThrottle
{
    // The same five a minute the recovery gate takes, because they are the two
    // guest credentials that reach the same account and a household that has
    // learned one number should not have to learn a second.
    public const int MAX_ATTEMPTS = 5;

    public function __construct(private RateLimiter $limiter) {}

    public function isExhausted(string $usernameInput): bool
    {
        return $this->limiter->tooManyAttempts($this->key($usernameInput), self::MAX_ATTEMPTS);
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

    // Keyed on what the caller typed, normalised, so an unknown username is
    // metered exactly like a known one. A key that existed only for real
    // accounts would answer in throttling the question the constant failure
    // message and the equalised hash both refuse to answer in words.
    private function key(string $usernameInput): string
    {
        return 'auth.sign-in:'.Username::normalize($usernameInput);
    }
}
