<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Auth\Public\Services\AppLockKeyService;

final class AppLockKeyProbe extends Component
{
    // Withholding the key affects this session only: queue and scheduler
    // workers hold their own copy, independent of any session lock state.
    public function lock(AppLockKeyService $keyService, Session $session): void
    {
        $keyService->withhold($session);
    }

    public function refresh(): void
    {
        // The round trip IS the refresh: render() re-reads the gate from the
        // session on every request, so a body here could only re-read what is
        // about to be read anyway.
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
