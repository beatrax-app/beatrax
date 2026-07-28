<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Services\SessionFactory;

// Locks the session immediately (no grace period) when the native
// window is hidden or closed — the OS app-switcher snapshot must never
// show financial data. Imports only the Public AppLockKeyService
// contract; Auth's Internal namespace stays off-limits.
/**
 * @link ../../../../.docs/features/desktop/architecture.md
 */
final class LockOnWindowHideOrClose
{
    public function __construct(
        private readonly AppLockKeyService $keyService,
        private readonly SessionFactory $session,
    ) {}

    // Accepts both WindowHidden and WindowClosed via the object type
    // hint so both route through this one handler; the event payload
    // itself is never inspected.
    public function handle(object $event): void
    {
        $this->keyService->withhold(($this->session)());
    }
}
