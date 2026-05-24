<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Session\Session;

/**
 * Clears `dev_mode.advanced` from the session on every successful
 * Login event.
 *
 * CONTEXT D-20: "Advanced toggle … Session-scoped, default OFF.
 * Resets to OFF on every login." This listener implements the
 * second half of that contract — without it, an Advanced toggle
 * flipped ON in a previous session would silently stay ON across
 * logout/login.
 *
 * Wired via `$events->listen(Login::class, [ResetAdvancedToggleOnLogin::class, 'handle'])`
 * inside DevModeServiceProvider::boot(). The pattern mirrors
 * Desktop's ContinuePendingFileIntentAfterLogin listener.
 *
 * Note: the runner page's mount() ALSO resets the toggle on the
 * first dev-console load per session as a belt-and-braces safeguard
 * (a single-page-app open across long-lived sessions might not
 * re-fire the Login event but might still resume an old Advanced
 * state from a re-hydrated browser tab; the mount() reset closes
 * that gap on the first /dev/* navigation).
 */
final readonly class ResetAdvancedToggleOnLogin
{
    public function __construct(
        private Session $session,
    ) {}

    public function handle(Login $event): void
    {
        $this->session->forget('dev_mode.advanced');
    }
}
