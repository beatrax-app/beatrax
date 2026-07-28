<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\OAuth;

use DateTimeImmutable;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Throwable;

/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
final class OpenBankingStateRepository
{
    private const SESSION_KEY = 'open_banking_oauth_state';

    // Ten minutes is long enough for a typical consent + SCA round-trip;
    // entries older than this are treated as expired and rejected.
    private const MAX_AGE_SECONDS = 600;

    public function __construct(
        private readonly SessionFactory $session,
        private readonly Clock $clock,
    ) {}

    public function issueState(int $userId): string
    {
        $state = bin2hex(random_bytes(32));
        ($this->session)()->put(self::SESSION_KEY, [
            'state' => $state,
            'user_id' => $userId,
            'issued_at' => $this->clock->now()->toDateTimeString(),
        ]);

        return $state;
    }

    public function consumeState(string $candidateState, int $currentUserId): bool
    {
        $entry = ($this->session)()->pull(self::SESSION_KEY);

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
