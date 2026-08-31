<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\OAuthStateWindow;
use Modules\EmailScan\Public\Enums\MailProvider;
use Throwable;

final readonly class OAuthStateRepository
{
    public function __construct(
        private SessionFactory $session,
        private Clock $clock,
    ) {}

    public function issueState(string $provider, int $userId, ?int $existingInboxId = null): string
    {
        $this->assertProvider($provider);

        $state = bin2hex(random_bytes(32));
        ($this->session)()->put($this->sessionKey($provider), [
            'state' => $state,
            'user_id' => $userId,
            'inbox_id' => $existingInboxId,
            'issued_at' => $this->clock->now()->toDateTimeString(),
        ]);

        return $state;
    }

    // Single-use: the session entry is removed whatever the outcome. Returns
    // the stored inbox id on a match, where 0 means the new-inbox flow.
    public function consumeState(string $provider, string $candidateState, int $currentUserId): ?int
    {
        $this->assertProvider($provider);

        $entry = ($this->session)()->pull($this->sessionKey($provider));

        if (! is_array($entry) || ! $this->entryIsValid($entry, $candidateState, $currentUserId)) {
            return null;
        }

        $inboxId = $entry['inbox_id'] ?? null;

        return is_int($inboxId) ? $inboxId : 0;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function entryIsValid(array $entry, string $candidateState, int $currentUserId): bool
    {
        // hash_equals, not `===`: the comparison must not leak the position
        // of the first differing byte through its running time.
        $storedState = $entry['state'] ?? null;
        if (! is_string($storedState) || $storedState === '' || ! hash_equals($storedState, $candidateState)) {
            return false;
        }

        // A change of session-bound user between authorize and callback
        // (shared browser) must not attach the inbox to the wrong account.
        $storedUserId = $entry['user_id'] ?? null;
        if (! is_int($storedUserId) || $storedUserId !== $currentUserId) {
            return false;
        }

        return $this->issuedAtWithinWindow($entry['issued_at'] ?? null);
    }

    // Bounds replay of a stale state value from a long-lived session; a
    // missing or unparseable timestamp counts as expired.
    private function issuedAtWithinWindow(mixed $issuedAtRaw): bool
    {
        if (! is_string($issuedAtRaw) || $issuedAtRaw === '') {
            return false;
        }
        try {
            $issuedAt = new DateTimeImmutable($issuedAtRaw);
        } catch (Throwable) {
            return false;
        }
        $ageSeconds = $this->clock->now()->getTimestamp() - $issuedAt->getTimestamp();

        return $ageSeconds >= 0 && $ageSeconds <= OAuthStateWindow::MAX_AGE_SECONDS;
    }

    public function storePkceVerifier(string $provider, string $verifier): void
    {
        $this->assertProvider($provider);
        if ($verifier === '') {
            return;
        }

        ($this->session)()->put($this->pkceSessionKey($provider), $verifier);
    }

    public function consumePkceVerifier(string $provider): ?string
    {
        $this->assertProvider($provider);

        $verifier = ($this->session)()->pull($this->pkceSessionKey($provider));

        return is_string($verifier) && $verifier !== '' ? $verifier : null;
    }

    public function issueClientWizardSuccess(string $provider): void
    {
        $this->assertProvider($provider);

        ($this->session)()->put(
            $this->wizardSessionKey($provider),
            $this->clock->now()->toDateTimeString(),
        );
    }

    private function assertProvider(string $provider): void
    {
        if (MailProvider::tryFrom($provider) === null) {
            throw new InvalidArgumentException(
                "OAuthStateRepository: provider must be 'gmail' or 'microsoft', got '{$provider}'.",
            );
        }
    }

    private function sessionKey(string $provider): string
    {
        return 'oauth_state_'.$provider;
    }

    private function pkceSessionKey(string $provider): string
    {
        return 'oauth_pkce_'.$provider;
    }

    private function wizardSessionKey(string $provider): string
    {
        return 'oauth_wizard_complete_'.$provider;
    }
}
