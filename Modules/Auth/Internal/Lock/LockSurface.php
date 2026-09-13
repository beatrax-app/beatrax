<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// What a locked session may still reach, because reaching it is how the
// session stops being locked. Two gates read this and both answer with a
// redirect, so a route one exempts and the other bounces is a loop with no
// exit rather than a rule with two halves.
/**
 * @link ../../../../.docs/features/auth/architecture.md#the-forced-change-cannot-foreclose-the-unlock
 */
final class LockSurface
{
    /**
     * @var list<string>
     */
    public const array ROUTE_NAMES = [
        'auth.lock',
        'auth.lock.biometric.challenge',
        'auth.lock.biometric.verify',
        'auth.lock.engage',
        'mobile.lock',
        'logout',
    ];

    // The unlock screens drive their PIN pads over the Livewire endpoint, which
    // carries its own route name, so exempting the route above is half of it.
    /**
     * @var list<string>
     */
    public const array LIVEWIRE_COMPONENTS = [
        'auth.lock-screen',
        'mobile.lock-screen',
    ];
}
