<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Desktop\Internal\Native\ShellHandoff;

// Bound to WindowHidden and WindowClosed; neither payload is inspected, so no
// parameter is bound. It locks nothing itself and must not pretend to: the
// shell's event transport carries no cookie, so it records that the window
// went away and ClaimShellLockDemand engages the lock where a session is real.
final readonly class DemandLockOnWindowHideOrClose
{
    public function __construct(
        private ShellHandoff $handoff,
    ) {}

    public function handle(): void
    {
        $this->handoff->leave(ShellHandoff::LOCK_DEMANDED);
    }
}
