<?php

declare(strict_types=1);

namespace Modules\Auth\Public;

// Names the app-lock Livewire events Auth emits across a component boundary,
// so a dispatcher and its #[On] listener (here the Auth settings section and
// Sync's devices panel) share one const instead of two hand-kept string
// literals that silently break the wiring on a typo.
final class AppLockEvents
{
    public const string CONFIGURED = 'app-lock-configured';
}
