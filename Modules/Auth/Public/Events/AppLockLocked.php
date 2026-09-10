<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Events;

use Illuminate\Contracts\Session\Session;

// The counterpart to AppLockUnlocked, announced from the same funnel: an idle
// re-lock, a sign-out, a sign-in that starts locked and a re-provision all
// arrive at one method, and a listener wired to fewer of them would let the
// others through.
/**
 * @link ../../../../.docs/features/auth/app-lock-data-key-lifetime.md
 */
final readonly class AppLockLocked
{
    // Carried rather than resolved, for the same reason the unlock event
    // carries it: the session being locked is not always the one the container
    // would answer with.
    public function __construct(
        public Session $session,
    ) {}
}
