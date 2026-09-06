<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Enums\SyncSessionStatus;
use Modules\Sync\Internal\Status\PeerFailure;
use Modules\Sync\Internal\Status\SessionLiveness;
use Modules\Sync\Public\Enums\SyncOverallStatus;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\SyncStatusService;
use Modules\Sync\Public\SyncEvents;

final class SyncStatusSection extends Component
{
    // Each element: peer_device_id (string), error_message (string|null),
    // last_seen_at (ISO8601|null), last_seen_human (e.g. "2m ago"), error_label
    // (human-readable error copy for UI), and the two decided flags is_live and
    // is_failed — the view is handed answers rather than a status to re-read.
    /**
     * @var list<array<string, mixed>>
     */
    public array $peerStatuses = [];

    // The backing value, not the case: a Livewire property rehydrates from the
    // client payload with no enum coercion, so the enum is rebuilt in render()
    // where the view needs it.
    public string $overallStatus = SyncOverallStatus::Unknown->value;

    public ?string $lastSyncedHuman = null;

    // Clock is injected here for the relative time derivation
    // (noGlobalLaravelFunction guard).
    public function mount(
        CurrentUser $currentUser,
        SyncStatusService $statusService,
        Clock $clock,
        DeviceRegistryService $devices,
    ): void {
        $userId = $currentUser->user()->id;
        $now = $clock->now();

        // A row named by its raw UUID tells the reader nothing about which
        // machine it is. The registry knows the names; a session whose device
        // is gone gets an honest label instead of 36 hex characters.
        $this->peerStatuses = $this->buildPeerViewModels(
            $statusService->peerStatuses($userId),
            $now,
            $devices->otherDeviceNames($userId),
        );
        $this->overallStatus = $statusService->overallStatus($userId)->value;
        $this->lastSyncedHuman = $statusService->lastSyncedHuman($now, $userId);
    }

    // A sync just ran in the sibling screen. Without this the block kept its
    // mount-time answer — "Not yet synced" — directly above that screen's own
    // "Synced with your other device", each contradicting the other until the
    // page was reloaded. Nothing here polls, so the dispatch is the only signal.
    #[On(SyncEvents::COMPLETED)]
    public function onSyncCompleted(
        CurrentUser $currentUser,
        SyncStatusService $statusService,
        Clock $clock,
        DeviceRegistryService $devices,
    ): void {
        $this->mount($currentUser, $statusService, $clock, $devices);
    }

    // Removes one recorded session and re-reads the surface, so the row and
    // any error it was holding disappear in the same round trip.
    public function dismissPeer(
        string $peerDeviceId,
        CurrentUser $currentUser,
        SyncStatusService $statusService,
        Clock $clock,
        DeviceRegistryService $devices,
    ): void {
        $statusService->forgetSession($currentUser->user()->id, $peerDeviceId);

        $this->mount($currentUser, $statusService, $clock, $devices);
    }

    // Clears every session with no device behind it — the state a user lands
    // in after removing a peer, where the list still shows raw UUIDs.
    public function dismissStale(
        CurrentUser $currentUser,
        SyncStatusService $statusService,
        Clock $clock,
        DeviceRegistryService $devices,
    ): void {
        $statusService->forgetOrphanedSessions($currentUser->user()->id);

        $this->mount($currentUser, $statusService, $clock, $devices);
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('sync::livewire.sync-status-section', [
            'overall' => SyncOverallStatus::tryFrom($this->overallStatus) ?? SyncOverallStatus::Unknown,
        ]);
    }

    /**
     * @param  array<int, \stdClass>  $rows
     * @param  CarbonImmutable  $now  Reference point for relative time.
     * @param  array<string, string>  $deviceNames  device_id => display name.
     * @return list<array<string, mixed>>
     */
    private function buildPeerViewModels(array $rows, CarbonImmutable $now, array $deviceNames = []): array
    {
        $viewModels = [];

        foreach ($rows as $row) {
            $vars = get_object_vars($row);

            $peerDeviceId = is_string($vars['peer_device_id'] ?? null) ? $vars['peer_device_id'] : '';
            $status = SyncSessionStatus::tryFrom(is_string($vars['status'] ?? null) ? $vars['status'] : '');
            $errorMessage = is_string($vars['error_message'] ?? null) && $vars['error_message'] !== '' ? $vars['error_message'] : null;
            $lastSeenAt = is_string($vars['last_seen_at'] ?? null) && $vars['last_seen_at'] !== '' ? $vars['last_seen_at'] : null;

            $lastSeenHuman = null;
            if ($lastSeenAt !== null) {
                $lastSeenHuman = SyncStatusService::relativeTime($now, $lastSeenAt);
            }

            $errorLabel = $this->deriveErrorLabel($status, $errorMessage);

            $viewModels[] = [
                'peer_device_id' => $peerDeviceId,
                'display_name' => $deviceNames[$peerDeviceId]
                    ?? Lang::get('sync::status.unknown_device'),
                'is_known' => isset($deviceNames[$peerDeviceId]),
                // Decided here rather than in the template: a row still claiming
                // to be live long after anything stamped it is a session whose
                // process is gone, and the pulsing dot is a statement about now.
                'is_live' => $status !== null
                    && $status->isLiveClaim()
                    && SessionLiveness::isStampRecent($lastSeenAt, $now),
                'is_failed' => $status === SyncSessionStatus::Failed,
                'error_message' => $errorMessage,
                'last_seen_at' => $lastSeenAt,
                'last_seen_human' => $lastSeenHuman,
                'error_label' => $errorLabel,
            ];
        }

        return $viewModels;
    }

    // Empty string when the row did not fail, so the view renders no label
    // rather than an empty one. The reading itself lives in PeerFailure, which
    // is also what decides whether the aggregate above calls this peer an error.
    private function deriveErrorLabel(?SyncSessionStatus $status, ?string $errorMessage): string
    {
        return $status === SyncSessionStatus::Failed ? Lang::get(PeerFailure::labelKey($errorMessage)) : '';
    }
}
