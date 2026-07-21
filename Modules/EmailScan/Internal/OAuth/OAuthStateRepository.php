<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use DateTimeImmutable;
use Illuminate\Contracts\Session\Session;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Throwable;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final class OAuthStateRepository
{
    private const ALLOWED_PROVIDERS = ['gmail', 'microsoft'];

    // Maximum lifetime of an issued state entry in seconds (10 minutes
    // covers a typical OAuth round-trip including an MFA prompt);
    // consumeState rejects entries older than this.
    private const MAX_AGE_SECONDS = 600;

    public function __construct(
        private readonly Session $session,
        private readonly Clock $clock,
    ) {}

    public function issueState(string $provider, int $userId, ?int $existingInboxId = null): string
    {
        $this->assertProvider($provider);

        $state = bin2hex(random_bytes(32));
        $this->session->put($this->sessionKey($provider), [
            'state' => $state,
            'user_id' => $userId,
            'inbox_id' => $existingInboxId,
            'issued_at' => $this->clock->now()->toDateTimeString(),
        ]);

        return $state;
    }

    // Single-use: the session entry is removed regardless of match
    // outcome. Returns the stored inbox id on match (0 = new-inbox
    // flow), null on any mismatch/missing/malformed/expired entry or
    // a stored user_id that doesn't match the caller-supplied one.
    public function consumeState(string $provider, string $candidateState, int $currentUserId): ?int
    {
        $this->assertProvider($provider);

        $key = $this->sessionKey($provider);
        $entry = $this->session->pull($key);

        if (! is_array($entry)) {
            return null;
        }

        $storedState = $entry['state'] ?? null;
        if (! is_string($storedState) || $storedState === '') {
            return null;
        }

        // hash_equals avoids the timing-attack a naive `===` would
        // expose; the comparison cost is constant in the prefix
        // match length regardless of input difference position.
        if (! hash_equals($storedState, $candidateState)) {
            return null;
        }

        // User-id binding: a change of session-bound user between
        // authorize and callback (shared browser, multi-user host)
        // must not attach the inbox to the wrong account.
        $storedUserId = $entry['user_id'] ?? null;
        if (! is_int($storedUserId) || $storedUserId !== $currentUserId) {
            return null;
        }

        // Issued-at expiry: reject state tokens older than the
        // configured window so a long-lived session cannot replay a
        // stale state value.
        $issuedAtRaw = $entry['issued_at'] ?? null;
        if (! is_string($issuedAtRaw) || $issuedAtRaw === '') {
            return null;
        }
        try {
            $issuedAt = new DateTimeImmutable($issuedAtRaw);
        } catch (Throwable) {
            return null;
        }
        $ageSeconds = $this->clock->now()->getTimestamp() - $issuedAt->getTimestamp();
        if ($ageSeconds < 0 || $ageSeconds > self::MAX_AGE_SECONDS) {
            return null;
        }

        $inboxId = $entry['inbox_id'] ?? null;

        return is_int($inboxId) ? $inboxId : 0;
    }

    public function issueClientWizardSuccess(string $provider): void
    {
        $this->assertProvider($provider);

        $this->session->put(
            $this->wizardSessionKey($provider),
            $this->clock->now()->toDateTimeString(),
        );
    }

    private function assertProvider(string $provider): void
    {
        if (! in_array($provider, self::ALLOWED_PROVIDERS, strict: true)) {
            throw new InvalidArgumentException(
                "OAuthStateRepository: provider must be 'gmail' or 'microsoft', got '{$provider}'.",
            );
        }
    }

    private function sessionKey(string $provider): string
    {
        return 'oauth_state_'.$provider;
    }

    private function wizardSessionKey(string $provider): string
    {
        return 'oauth_wizard_complete_'.$provider;
    }
}
