<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\OAuth;

use DateTimeImmutable;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\OAuthStateWindow;
use Throwable;

final readonly class OpenBankingStateRepository
{
    private const string SESSION_KEY = 'open_banking_oauth_state';

    public function __construct(
        private SessionFactory $session,
        private Clock $clock,
    ) {}

    // The institution rides in the state rather than in the secrets file: the
    // callback has to know which bank it is finishing, and a store that could
    // only hold one answer to that is what made a second bank unusable.
    public function issueState(int $userId, string $institutionId): string
    {
        $state = bin2hex(random_bytes(32));
        ($this->session)()->put(self::SESSION_KEY, [
            'state' => $state,
            'user_id' => $userId,
            'institution_id' => $institutionId,
            'issued_at' => $this->clock->now()->toDateTimeString(),
        ]);

        return $state;
    }

    // The institution the consumed state was issued for, or null when the
    // state is not this reader's, is spent, or has aged out.
    public function consumeState(string $candidateState, int $currentUserId): ?string
    {
        $entry = ($this->session)()->pull(self::SESSION_KEY);
        $entry = is_array($entry) ? $entry : [];

        $storedState = $entry['state'] ?? null;
        $storedUserId = $entry['user_id'] ?? null;
        $storedInstitutionId = $entry['institution_id'] ?? null;
        $issuedAtRaw = $entry['issued_at'] ?? null;

        // hash_equals avoids the timing attack `===` would expose, and the
        // user-id binding forces the consent to finish under the user who began it.
        if (! is_string($storedState) || $storedState === ''
            || ! hash_equals($storedState, $candidateState)
            || ! is_int($storedUserId) || $storedUserId !== $currentUserId
            || ! is_string($storedInstitutionId) || $storedInstitutionId === ''
            || ! is_string($issuedAtRaw) || $issuedAtRaw === '') {
            return null;
        }

        $issuedAt = $this->parseIssuedAt($issuedAtRaw);
        $ageSeconds = $issuedAt === null
            ? null
            : $this->clock->now()->getTimestamp() - $issuedAt->getTimestamp();

        $fresh = $ageSeconds !== null && $ageSeconds >= 0 && $ageSeconds <= OAuthStateWindow::MAX_AGE_SECONDS;

        return $fresh ? $storedInstitutionId : null;
    }

    private function parseIssuedAt(string $issuedAtRaw): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($issuedAtRaw);
        } catch (Throwable) {
            return null;
        }
    }
}
