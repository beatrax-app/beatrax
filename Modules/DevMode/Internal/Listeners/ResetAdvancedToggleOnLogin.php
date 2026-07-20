<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Listeners;

use Illuminate\Auth\Events\Login;
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

    public function handle(Login $event): void
    {
        $this->session->forget('dev_mode.advanced');
    }
}
