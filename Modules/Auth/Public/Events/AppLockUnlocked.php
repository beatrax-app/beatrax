<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Events;

use Illuminate\Contracts\Session\Session;

// A PHP event and not the browser kind AppLockEvents carries: an unlock lands
// in a service during an ordinary request — a lock-screen POST, a sign-in —
// where no Livewire component is mounted for #[On] to reach.
/**
 * @link ../../../../.docs/features/auth/app-lock-data-key-lifetime.md
 */
final readonly class AppLockUnlocked
{
    // The session is carried rather than resolved: MobileLockGateway and the
    // test harness both unlock a session they were handed, which is not
    // always the one the container would answer with.
    public function __construct(
        public Session $session,
    ) {}
}
