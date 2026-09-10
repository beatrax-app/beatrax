<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Modules\Core\Public\Contracts\Clock;

// A PIN typed a moment ago, for the one enrolment that cannot finish in the
// request that took it: the browser ceremony leaves and comes back, so the
// proof has to survive the gap without the key doing so too.
/**
 * @link ../../../../.docs/design/cold-start-biometric-unlock.md
 */
final readonly class FreshPinProof
{
    private const string SESSION_KEY = 'beatrax_fresh_pin_proof';

    // Long enough for a fingerprint sheet somebody has to reach for, short
    // enough that "typed now" still means it. A ceremony abandoned and picked
    // up later costs the PIN again, which is the whole point of asking.
    public const int LIFETIME_SECONDS = 120;

    public function __construct(private Clock $clock) {}

    public function mint(Session $session, int $userId): void
    {
        $session->put(self::SESSION_KEY, [
            'user_id' => $userId,
            'expires_at' => $this->clock->now()->addSeconds(self::LIFETIME_SECONDS)->toDateTimeString(),
        ]);
    }

    // Drained first and judged after, like every other single-use claim here: a
    // read that returns without taking it leaves something a later request can
    // spend, and the whole value of the proof is that it is spent once.
    public function consume(Session $session, int $userId): bool
    {
        $held = $session->pull(self::SESSION_KEY);

        return is_array($held)
            && ($held['user_id'] ?? null) === $userId
            && self::stillStands($held['expires_at'] ?? null, $this->clock->now());
    }

    private static function stillStands(mixed $expiresAt, CarbonImmutable $now): bool
    {
        return is_string($expiresAt) && $now < CarbonImmutable::parse($expiresAt);
    }
}
