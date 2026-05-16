<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use Illuminate\Contracts\Session\Session;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;

/**
 * Per-flow random OAuth state, stored in the Laravel session.
 *
 * Each issue() call generates a 64-character hex token (32 random
 * bytes) and stashes it in the session under a per-provider key.
 * consume() pops the value (single-use) and returns the associated
 * inbox id when the candidate state matches via hash_equals; null
 * otherwise. The single-use semantics prevent a replay-attack from
 * reusing a leaked state value.
 *
 * The implementation injects the framework's Session contract + the
 * project's Clock contract — no auth(), session(), or now() global
 * helpers — per the project-wide DI invariant.
 */
final class OAuthStateRepository
{
    private const ALLOWED_PROVIDERS = ['gmail', 'microsoft'];

    public function __construct(
        private readonly Session $session,
        private readonly Clock $clock,
    ) {}

    public function issueState(string $provider, ?int $existingInboxId = null): string
    {
        $this->assertProvider($provider);

        $state = bin2hex(random_bytes(32));
        $this->session->put($this->sessionKey($provider), [
            'state' => $state,
            'inbox_id' => $existingInboxId,
            'issued_at' => $this->clock->now()->toDateTimeString(),
        ]);

        return $state;
    }

    /**
     * Single-use: the session entry is removed regardless of match
     * outcome. Returns the stored inbox id on match (0 = new-inbox
     * flow), null on mismatch / missing / malformed entry.
     */
    public function consumeState(string $provider, string $candidateState): ?int
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
