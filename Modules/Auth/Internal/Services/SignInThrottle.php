<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Services;

// The meter both sign-in paths share. LoginAction serves the Livewire form and
// Fortify's own pipeline serves a post that arrived before Livewire booted, so
// a limit written into one of them is a limit the other walks around.
final readonly class SignInThrottle extends GuestCredentialMeter
{
    protected function keyPrefix(): string
    {
        return 'auth.sign-in:';
    }
}
