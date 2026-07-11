<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Mobile\Internal\Sync\MobileSyncTriggerService;
use Modules\Mobile\Internal\Sync\NetworkPolicyResolver;

/**
 * The dedicated `/sync` status surface (D-05/D-06, R7, MOBILE-02) — the
 * mobile peer's single primary observe/un-stale surface for sync health.
 *
 * Renders the full R7 state-set truthfully:
 *   - idle: "all devices up to date · synced Xm ago" (embedded
 *     `sync.sync-status-section`, unchanged, reads via SyncStatusService).
 *   - active: the embedded component's own "Syncing…" banner PLUS this
 *     screen's own "{n} of {m} records" initial-sync progress line,
 *     sourced from the own-module `mobile_sync_progress` durable cursor
 *     (never `InitialSyncPuller` — this plan does not depend on Plan 08's
 *     class, per 15-10-PLAN.md's own scope note).
 *   - offline/not-connected: the embedded component's amber "Devices
 *     offline" banner.
 *   - per-device list: already rendered by the embedded component — no
 *     duplicate list here (UI-SPEC "reuse, not rebuild").
 *
 * Also hosts the manual "Sync now" control (D-08, wired to
 * `MobileSyncTriggerService::syncOnce()`) and the "Pause sync on
 * cellular" toggle (D-10, `NetworkPolicyResolver`).
 *
 * Cross-module rule: status is read exclusively via the embedded
 * `sync.sync-status-section` component (itself reading via
 * `SyncStatusService`, a Public service) or this module's OWN
 * `mobile_sync_progress` table — never any Sync-module internal session
 * or relay table directly (T-15-26/T-15-28).
 *
 * No constructor — Livewire components never receive constructor DI per
 * the project's larastan-strict-rules profile (`SyncHealthPage`/
 * `SyncStatusSection`/`SetupProgressScreen` precedent); every collaborator
 * arrives as a method-DI parameter on mount()/render()/the action methods.
 *
 * Already routed (`/sync`, named `sync.index`) and Livewire-tag-registered
 * (`mobile.sync-screen`) by the Plan 01 `MobileServiceProvider`/
 * `Routes/web.php` — this class does not edit either file.
 */
#[Layout('layouts.app')]
final class SyncScreen extends Component
{
    /**
     * Whether an initial-sync pull is currently mid-flight for this user
     * (`mobile_sync_progress.phase === 'pulling'`) — drives the "Syncing…
     * {n} of {m} records" progress line.
     */
    public bool $initialSyncInProgress = false;

    public int $progressApplied = 0;

    public ?int $progressExpected = null;

    /** Plain-integer percent [0, 100] — no float (project convention). */
    public int $progressPercent = 0;

    /** D-10 "Pause sync on cellular" toggle state (default OFF). */
    public bool $pauseOnCellular = false;

    public function mount(
        CurrentUser $currentUser,
        DatabaseManager $db,
        NetworkPolicyResolver $networkPolicy,
    ): void {
        $this->hydrateProgress($currentUser->id(), $db);
        $this->pauseOnCellular = $networkPolicy->pauseOnCellular();
    }

    /**
     * D-08 manual "Sync now" trigger. Runs ONE bounded sync burst via
     * `MobileSyncTriggerService::syncOnce()` (LAN peer discovery is out of
     * this plan's scope — the trigger falls through to whatever transport
     * it can already reach, mirroring `SetupProgressScreen::poll()`'s own
     * no-lanHost call shape), then re-fetches this screen's own progress
     * state so the "{n} of {m} records" line reflects reality immediately
     * after the attempt.
     */
    public function syncNow(
        CurrentUser $currentUser,
        MobileSyncTriggerService $trigger,
        Session $session,
        DatabaseManager $db,
    ): void {
        $trigger->syncOnce($currentUser->id(), $session);

        $this->hydrateProgress($currentUser->id(), $db);
    }

    /**
     * D-10 "Pause sync on cellular" toggle — reads/writes
     * `NetworkPolicyResolver`'s file-backed policy (never a Sync-module
     * table).
     */
    public function toggleCellularPause(NetworkPolicyResolver $networkPolicy): void
    {
        $this->pauseOnCellular = ! $this->pauseOnCellular;
        $networkPolicy->setPauseOnCellular($this->pauseOnCellular);
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('mobile::livewire.sync-screen');
    }

    /**
     * Read the durable `mobile_sync_progress` cursor for $userId (own
     * module table, never a Sync-module table) and derive the initial-sync
     * "active" state + plain-integer percent. Mirrors
     * `InitialSyncPuller::progress()`'s read shape without depending on
     * that class (15-10-PLAN.md's own scope note — this plan does not
     * depend on Plan 08's `InitialSyncPuller`).
     */
    private function hydrateProgress(int $userId, DatabaseManager $db): void
    {
        $row = $db->connection()
            ->table('mobile_sync_progress')
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->first();

        if ($row === null) {
            $this->initialSyncInProgress = false;
            $this->progressApplied = 0;
            $this->progressExpected = null;
            $this->progressPercent = 0;

            return;
        }

        $applied = is_numeric($row->records_applied) ? (int) $row->records_applied : 0;
        $expected = is_numeric($row->records_expected) ? (int) $row->records_expected : null;
        $phase = is_string($row->phase) ? $row->phase : 'pending';

        $this->initialSyncInProgress = $phase === 'pulling';
        $this->progressApplied = $applied;
        $this->progressExpected = $expected;
        $this->progressPercent = ($expected === null || $expected <= 0)
            ? 0
            : max(0, min(100, intdiv($applied * 100, $expected)));
    }
}
