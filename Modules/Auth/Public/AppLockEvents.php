<?php

declare(strict_types=1);

namespace Modules\Auth\Public;

// The dispatcher (Auth's settings section) and the #[On] listener (Sync's
// devices panel) sit in different modules; this is the shared name.
final class AppLockEvents
{
    public const string CONFIGURED = 'app-lock-configured';
}
