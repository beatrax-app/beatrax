<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Auth\Public\Services\AppLockKeyService;

/**
 * Dev-console probe for the LOCK-04 data-key release gate (D-19).
 *
 * Exercises the exact Public contract that Phase 14 (FX encryption) will
 * consume: AppLockKeyService::release(Session) returns the data key when
 * the session is unlocked, and null when locked. This panel makes that
 * gate visually inspectable in the Developer Console without Phase 14
 * having to be deployed.
 *
 * Security contract (T-05-26):
 *   - The probe renders ONLY a truncated SHA-256 fingerprint of the key
 *     (first 8 hex chars of sha256($key)), never the raw key bytes.
 *   - The fingerprint proves key presence without enabling recovery.
 *   - The AppLockKeyProbeTest asserts assertDontSee($rawKey) on every render.
 *
 * D-22 (lock gates UI; long-lived workers keep the key):
 *   The lock state gates the PER-SESSION UI release only. Background queue
 *   and scheduler workers hold their own in-memory copy of the data key,
 *   independent of any session lock state. Locking the UI (calling withhold()
 *   here) never prevents a running worker from accessing the key — only the
 *   next call to release() in THIS session returns null. Phase 14 callers
 *   in worker context must obtain the key before the session lock fires.
 *
 * D-27 (developer-gated surface):
 *   This component is mounted only on the dev-overview page, which is
 *   behind the `developer` middleware. It must NEVER appear on any
 *   non-developer surface.
 *
 * Constructor-free Livewire component: service collaborators arrive as
 * parameters on the action methods and render() — the same pattern as
 * LockScreen and AppLockSettingsSection.
 */
final class AppLockKeyProbe extends Component
{
    /**
     * Lock action — calls withhold() so the developer can watch the gate flip.
     *
     * D-22 note: this only withholds the key in the current session.
     * Long-lived background workers are unaffected.
     */
    public function lock(AppLockKeyService $keyService, Session $session): void
    {
        $keyService->withhold($session);
    }

    /**
     * Refresh action — re-renders the probe with the current gate state.
     * Useful to confirm the gate flipped after an external lock event.
     */
    public function refresh(): void
    {
        // No-op: Livewire re-renders automatically after any action call.
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
