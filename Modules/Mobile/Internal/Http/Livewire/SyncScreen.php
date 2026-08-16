<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Sync\MobileSyncTriggerService;
use Modules\Mobile\Internal\Sync\NetworkPolicyResolver;
use Modules\Mobile\Internal\Sync\PeerLanAddress;
use Modules\Sync\Public\Services\DeviceRegistryService;

/**
 * @link ../../../../../.docs/features/mobile/architecture.md
 */
final class SyncScreen extends Component
{
    // Whether an initial-sync pull is currently mid-flight for this user -
    // drives the "Syncing... {n} of {m} records" progress line.
    public bool $initialSyncInProgress = false;

    public int $progressApplied = 0;

    public ?int $progressExpected = null;

    public int $progressPercent = 0;

    public bool $pauseOnCellular = false;

    // No confirmed peer means "Sync now" has nothing to talk to: the burst
    // would dial nobody and report success, which reads as a working sync on
    // a device that has never been paired.
    public bool $hasPeers = false;

    public function mount(
        CurrentUser $currentUser,
        DatabaseManager $db,
        NetworkPolicyResolver $networkPolicy,
        DeviceRegistryService $devices,
    ): void {
        $this->hydrateProgress($currentUser->id(), $db);
        $this->pauseOnCellular = $networkPolicy->pauseOnCellular();
        $this->hasPeers = $devices->otherDeviceNames($currentUser->id()) !== [];
    }

    // Manual "Sync now" trigger. Runs one bounded sync burst, falling
    // through to whatever transport it can already reach, then re-fetches
    // this screen's own progress state so the record line reflects
    // reality immediately after the attempt.
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

        // Same reason as the setup screen: without an address the LAN leg is
        // skipped and the relay fallback applies nothing.
        $trigger->syncOnce($currentUser->id(), $session, $peerAddress->host(), $peerAddress->port());

        $this->hydrateProgress($currentUser->id(), $db);

        // A pairing may have completed since mount; re-read rather than
        // trusting the value the button was rendered with.
        $this->hasPeers = $devices->otherDeviceNames($currentUser->id()) !== [];
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

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('mobile::sync.page_title').' · Beatrax']);

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
