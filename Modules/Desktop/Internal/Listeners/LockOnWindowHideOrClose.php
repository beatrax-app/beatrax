<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Services\SessionFactory;

// Locks immediately, with no grace period: the OS app-switcher snapshot must
// never show financial data.
final readonly class LockOnWindowHideOrClose
{
    public function __construct(
        private AppLockKeyService $keyService,
        private SessionFactory $session,
    ) {}

    // Wired to both WindowHidden and WindowClosed; neither payload is inspected,
    // so no parameter is bound.
    public function handle(): void
    {
        $this->keyService->withhold(($this->session)());
    }
}
