<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use DateTimeImmutable;
use Illuminate\Contracts\Session\Session;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Throwable;

/**
 * Per-flow random OAuth state, stored in the Laravel session.
 *
 * Each issue() call generates a 64-character hex token (32 random
 * bytes) and stashes it in the session under a per-provider key.
 * consume() pops the value (single-use) and returns the associated
 * inbox id when the candidate state matches via hash_equals AND the
 * stored user_id matches the caller-supplied current user id; null
 * otherwise. The single-use semantics prevent a replay-attack from
 * reusing a leaked state value, and the user-id binding closes the
 * cross-user-attach window that arises when the authenticated user
 * changes between authorize and callback (shared browser, session
 * reuse, or a future multi-user install).
 *
 * The entry also carries an issued-at timestamp; consumeState rejects
 * entries older than MAX_AGE_SECONDS so a state token that survives
 * an unusually long session cannot be replayed days later.
 *
 * The implementation injects the framework's Session contract + the
 * project's Clock contract — no auth(), session(), or now() global
 * helpers — per the project-wide DI invariant.
 */
final class OAuthStateRepository
{
    private const ALLOWED_PROVIDERS = ['gmail', 'microsoft'];

    /**
     * Maximum lifetime of an issued state entry, in seconds. Ten
     * minutes is long enough for a typical OAuth round-trip including
     * an MFA prompt; entries older than this are treated as expired
     * and rejected by consumeState.
     */
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

    /**
     * Single-use: the session entry is removed regardless of match
     * outcome. Returns the stored inbox id on match (0 = new-inbox
     * flow), null on mismatch / missing / malformed / expired entry
     * or when the stored user_id does not match the caller-supplied
     * current user id.
     */
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

        // User-id binding: the consent flow must complete under the
        // same authenticated user that started it. A change of
        // session-bound user between authorize and callback (shared
        // browser, multi-user host) must NOT attach the inbox to the
        // wrong account.
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
