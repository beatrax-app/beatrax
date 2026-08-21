<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Listeners;

use Illuminate\Contracts\Session\Session;

// Without this, Advanced=true would survive logout/login. ArtisanRunnerPage
// ::mount() resets it too, for the resume path that fires Authenticated only.
final readonly class ResetAdvancedToggleOnLogin
{
    public function __construct(
        private Session $session,
    ) {}

    public function handle(): void
    {
        $this->session->forget('dev_mode.advanced');
    }
}
