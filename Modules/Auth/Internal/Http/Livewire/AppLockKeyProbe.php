<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Auth\Public\Services\AppLockKeyService;

/**
 * @link ../../../../../.docs/features/auth/architecture.md
 */
final class AppLockKeyProbe extends Component
{
    // Withholding the key only affects THIS session. Background
    // queue/scheduler workers hold their own in-memory copy independent
    // of any session lock state, so a running worker is unaffected.
    public function lock(AppLockKeyService $keyService, Session $session): void
    {
        $keyService->withhold($session);
    }

    public function refresh(): void
    {
        // No-op: Livewire re-renders automatically after any action call,
        // so this button only needs to exist to trigger that round-trip.
    }

    public function render(ViewFactory $views, AppLockKeyService $keyService, Session $session): View
    {
        $key = $keyService->release($session);
        $status = $key !== null ? 'released' : 'withheld';
        $fingerprint = $key !== null ? substr(hash('sha256', $key), 0, 8) : null;

        return $views->make('auth::livewire.app-lock-key-probe', [
            'status' => $status,
            'fingerprint' => $fingerprint,
        ]);
    }
}
