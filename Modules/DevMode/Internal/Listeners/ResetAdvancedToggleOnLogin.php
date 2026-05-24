<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Session\Session;

/**
 * Clears `dev_mode.advanced` from the session on every successful
 * Login event.
 *
 * The Advanced toggle is session-scoped and default-OFF; it resets
 * on every login. Without this listener, an Advanced=true value
 * flipped ON in a previous session would silently survive
 * logout/login.
 *
 * Wired via `$events->listen(Login::class, [ResetAdvancedToggleOnLogin::class, 'handle'])`
 * inside DevModeServiceProvider::boot(). The pattern mirrors
 * Desktop's ContinuePendingFileIntentAfterLogin listener.
 *
 * ArtisanRunnerPage::mount() ALSO resets the toggle on the first
 * dev-console load per session as a belt-and-braces safeguard:
 * Laravel's SessionGuard fires Login on explicit credential auth
 * and on remember-me cookie hydration, but a plain session-resume
 * (user id still in session, no remember-me involved) fires only
 * the Authenticated event. The mount() reset closes that gap so a
 * long-lived sticky session cannot carry an Advanced=true value
 * forward indefinitely.
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
