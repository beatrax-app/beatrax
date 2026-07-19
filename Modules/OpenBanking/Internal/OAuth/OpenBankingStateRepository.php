<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\OAuth;

use DateTimeImmutable;
use Illuminate\Contracts\Session\Session;
use Modules\Core\Public\Contracts\Clock;
use Throwable;

/**
 * Per-flow random CSRF state for the Open Banking consent dance, stored
 * in the Laravel session — mirrors
 * `Modules\EmailScan\Internal\OAuth\OAuthStateRepository` (19-PATTERNS.md),
 * simplified for OpenBanking's single-provider (Enable Banking) shape:
 * there is no per-provider dispatch (EmailScan's state entry is keyed
 * per gmail/microsoft; OpenBanking has exactly one provider) and no
 * reconnect-id thread through the state payload — re-linking re-runs the
 * full onboarding wizard (institution pick included) rather than passing
 * an existing-connection id through the CSRF state, so `consumeState()`
 * only needs to prove "the same authenticated user that started this
 * flow is the one completing it," not resolve a prior row.
 *
 * `issueState()` generates a 64-char hex token (32 random bytes) bound
 * to the initiating user id. `consumeState()` is single-use (the session
 * entry is pulled — removed — regardless of outcome) and rejects on
 * state mismatch (constant-time via `hash_equals`), user-id mismatch, or
 * an entry older than `MAX_AGE_SECONDS`.
 */
final class OpenBankingStateRepository
{
    private const SESSION_KEY = 'open_banking_oauth_state';

    /**
     * Maximum lifetime of an issued state entry, in seconds. Ten minutes
     * is long enough for a typical consent + SCA round-trip; entries
     * older than this are treated as expired and rejected.
     */
    private const MAX_AGE_SECONDS = 600;

    public function __construct(
        private readonly Session $session,
        private readonly Clock $clock,
    ) {}

    public function issueState(int $userId): string
    {
        $state = bin2hex(random_bytes(32));
        $this->session->put(self::SESSION_KEY, [
            'state' => $state,
            'user_id' => $userId,
            'issued_at' => $this->clock->now()->toDateTimeString(),
        ]);

        return $state;
    }

    /**
     * Single-use: the session entry is removed regardless of match
     * outcome. Returns true on a valid match, false otherwise (mismatch,
     * missing/malformed entry, wrong user, or expired).
     */
    public function consumeState(string $candidateState, int $currentUserId): bool
    {
        $entry = $this->session->pull(self::SESSION_KEY);

        if (! is_array($entry)) {
            return false;
        }

        $storedState = $entry['state'] ?? null;
        if (! is_string($storedState) || $storedState === '') {
            return false;
        }

        // hash_equals avoids the timing-attack a naive `===` would
        // expose; the comparison cost is constant in the prefix match
        // length regardless of input difference position.
        if (! hash_equals($storedState, $candidateState)) {
            return false;
        }

        // User-id binding: the consent flow must complete under the
        // same authenticated user that started it.
        $storedUserId = $entry['user_id'] ?? null;
        if (! is_int($storedUserId) || $storedUserId !== $currentUserId) {
            return false;
        }

        $issuedAtRaw = $entry['issued_at'] ?? null;
        if (! is_string($issuedAtRaw) || $issuedAtRaw === '') {
            return false;
        }
        try {
            $issuedAt = new DateTimeImmutable($issuedAtRaw);
        } catch (Throwable) {
            return false;
        }
        $ageSeconds = $this->clock->now()->getTimestamp() - $issuedAt->getTimestamp();

        return $ageSeconds >= 0 && $ageSeconds <= self::MAX_AGE_SECONDS;
    }
}
