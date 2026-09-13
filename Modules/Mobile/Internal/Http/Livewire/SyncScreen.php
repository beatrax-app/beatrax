<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Brand;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Sync\MobileSyncTriggerService;
use Modules\Mobile\Internal\Sync\NetworkPolicyResolver;
use Modules\Mobile\Internal\Sync\PeerLanAddress;
use Modules\Mobile\Internal\Sync\SyncAttemptOutcome;
use Modules\Mobile\Internal\Sync\SyncPhase;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\SyncEvents;

final class SyncScreen extends Component
{
    // Whether an initial-sync pull is currently mid-flight for this user -
    // drives the "Syncing... {n} of {m} records" progress line.
    public bool $initialSyncInProgress = false;

    public int $progressApplied = 0;

    public ?int $progressExpected = null;

    public int $progressPercent = 0;

    // The cursor's expected count is max(previous, applied), so it equals what
    // has already landed unless a genuine total was written over it. Without
    // this the bar was full from the first record on and never moved again.
    public bool $progressMeasured = false;

    public bool $pauseOnCellular = false;

    // This screen is a plain web route with no platform gate, so the desktop
    // renders it too. The cellular pause and the tap-only note below it are
    // both phone-only facts: nothing on the desktop reads that policy file,
    // and the desktop listens for peers the whole time it is open.
    #[Locked]
    public bool $onPhone = false;

    // No confirmed peer means "Sync now" has nothing to talk to: the burst
    // would dial nobody and report success, which reads as a working sync on
    // a device that has never been paired.
    public bool $hasPeers = false;

    // The SyncAttemptOutcome the last press produced, null before the first
    // one. Locked because it names the sentence the screen renders and only
    // the service is entitled to choose which.
    #[Locked]
    public ?string $lastSyncResult = null;

    public function mount(
        CurrentUser $currentUser,
        DatabaseManager $db,
        NetworkPolicyResolver $networkPolicy,
        DeviceRegistryService $devices,
    ): void {
        $this->hydrateProgress($currentUser->id(), $db);
        $this->pauseOnCellular = $networkPolicy->pauseOnCellular();
        $this->onPhone = UserDataPathService::platform() !== null;
        $this->hasPeers = $devices->otherDeviceNames($currentUser->id()) !== [];
    }

    // Manual "Sync now" trigger. Runs one bounded sync burst, falling
    // through to whatever transport it can already reach, then re-fetches
    // this screen's own progress state so the record line reflects
    // reality immediately after the attempt.
    /**
     * @link ../../../../../.docs/features/mobile/background-sync-cannot-hold-the-key.md#the-sync-now-button-was-a-silent-no-op
     */
    public function syncNow(
        CurrentUser $currentUser,
        MobileSyncTriggerService $trigger,
        Session $session,
        DatabaseManager $db,
        DeviceRegistryService $devices,
        PeerLanAddress $peerAddress,
    ): void {
        if (! $this->hasPeers) {
            return;
        }

        $outcome = $this->dialEachPeer($currentUser->id(), $trigger, $session, $devices, $peerAddress);

        // The outcome is kept rather than dropped — a press that changes
        // nothing on screen is indistinguishable from a sync that worked.
        $this->lastSyncResult = $outcome->value;

        // The status block beside this one reads its answer at mount and has
        // no poll, so without this it goes on saying "Not yet synced" next to
        // the line above reporting the sync that just finished.
        $this->dispatch(SyncEvents::COMPLETED);

        $this->hydrateProgress($currentUser->id(), $db);

        // A pairing may have completed since mount; re-read rather than
        // trusting the value the button was rendered with.
        $this->hasPeers = $devices->otherDeviceNames($currentUser->id()) !== [];
    }

    // Every confirmed peer in turn, not only the first. The phone runs no
    // listener, so a desktop it never dials is one it never syncs with, and a
    // household's second desktop was unreachable for as long as its first
    // existed — asleep or not.
    private function dialEachPeer(
        int $userId,
        MobileSyncTriggerService $trigger,
        Session $session,
        DeviceRegistryService $devices,
        PeerLanAddress $peerAddress,
    ): SyncAttemptOutcome {
        $result = SyncAttemptOutcome::Unreachable;

        foreach (array_keys($devices->otherDeviceNames($userId)) as $peerDeviceId) {
            $outcome = $this->dialOnePeer($userId, $trigger, $session, $peerAddress, $peerDeviceId);

            // Unreachable says the least of what a walk can end on, so a peer
            // nothing answered for never displaces one that answered and
            // refused — which is the line this reader needs to see.
            $result = $outcome === SyncAttemptOutcome::Unreachable ? $result : $outcome;

            if ($outcome->endsTheWalk()) {
                break;
            }
        }

        return $result;
    }

    private function dialOnePeer(
        int $userId,
        MobileSyncTriggerService $trigger,
        Session $session,
        PeerLanAddress $peerAddress,
        string $peerDeviceId,
    ): SyncAttemptOutcome {
        // Where this device last REACHED that desktop. The relay endpoint's
        // host used to stand in for it, so a LAN-paired phone passed a null
        // host, skipped the LAN leg, drained a relay it had never configured,
        // and reported nothing.
        $dial = $peerAddress->locate($userId, $peerDeviceId);

        $outcome = $trigger->attempt($userId, $session, $dial);

        // An address that was dialled and reached nobody is a desktop that
        // moved or went away. Kept, every later press retries the same dead
        // address; dropped, the next one browses for the live one.
        if ($dial !== null && $outcome === SyncAttemptOutcome::Unreachable) {
            $peerAddress->forget($userId, $peerDeviceId);
        }

        return $outcome;
    }

    // Reads/writes NetworkPolicyResolver's file-backed policy, never a
    // Sync-module table.
    public function toggleCellularPause(NetworkPolicyResolver $networkPolicy): void
    {
        $this->pauseOnCellular = ! $this->pauseOnCellular;
        $networkPolicy->setPauseOnCellular($this->pauseOnCellular);
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('mobile::livewire.sync-screen');

        $view->extends('layouts.app', ['title' => Lang::get('mobile::sync.page_title').Brand::TITLE_SUFFIX]);

        return $view;
    }

    // Reads the durable mobile_sync_progress cursor for $userId (own
    // module table, never a Sync-module table) and derives the
    // initial-sync "active" state + plain-integer percent, without
    // depending on InitialSyncPuller.
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
            $this->progressMeasured = false;

            return;
        }

        $applied = is_numeric($row->records_applied) ? (int) $row->records_applied : 0;
        $expected = is_numeric($row->records_expected) ? (int) $row->records_expected : null;
        $phase = SyncPhase::fromStorage($row->phase);

        // A total that merely equals what has landed is the cursor's own
        // max(previous, applied) written back, not a count of what is coming.
        $measuredTotal = $expected !== null && $expected > $applied ? $expected : null;

        $this->initialSyncInProgress = $phase->isInitialSyncInFlight();
        $this->progressApplied = $applied;
        $this->progressExpected = $expected;
        $this->progressMeasured = $measuredTotal !== null;
        $this->progressPercent = $measuredTotal === null
            ? 0
            : max(0, min(100, intdiv($applied * 100, $measuredTotal)));
    }
}
