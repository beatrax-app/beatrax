<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Listeners;

use Illuminate\Contracts\Session\Session;

// Without this listener an Advanced=true value flipped ON in a previous
// session would silently survive logout/login. ArtisanRunnerPage::mount()
// also resets it on first dev-console load per session, covering the
// session-resume path that fires only Authenticated, never Login.
final readonly class ResetAdvancedToggleOnLogin
{
    public function __construct(
        private Session $session,
    ) {}

    // Registered explicitly against Login::class in the provider, so the
    // event payload is not needed to route here and is left unbound; the
    // toggle reset is unconditional on any successful login.
    public function handle(): void
    {
        $this->session->forget('dev_mode.advanced');
    }
}
