<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Sync\Public\Services\SyncStatusService;

/**
 * Sync-status surface for the "Devices & Sync" settings section (D-06).
 *
 * Renders:
 *   - An overall "all devices up to date · synced Nm ago" line (or error/offline state).
 *   - A per-peer list with online/offline dot, last-seen relative time, and
 *     explicit error states (can't reach peer, relay unreachable, handshake failure).
 *
 * Cross-module rule: reads peer status exclusively via SyncStatusService (public
 * service) — never queries sync_sessions directly (T-13-16 / CLAUDE.md).
 *
 * No constructor — all collaborators arrive via mount()/render() method DI per
 * the project's larastan-strict-rules profile (noGlobalLaravelFunction + the
 * Livewire "no constructor DI" pattern in DevicesAndSyncSettingsSection).
 *
 * Singleton-registered in SyncServiceProvider via the singletonIfExists() guard
 * that already forward-registered 'Modules\Sync\Public\Services\SyncStatusService'.
 * The Livewire component name 'sync.sync-status-section' is also already guarded
 * in SyncServiceProvider::boot(). No provider edits needed.
 */
final class SyncStatusSection extends Component
{
    /**
     * Per-peer session status rows (view-model arrays, safe for Livewire wire serialisation).
     *
     * Each element: [
     *   'peer_device_id' => string,
     *   'status'         => string,  // 'connecting'|'handshaking'|'active'|'closed'|'failed'
     *   'error_message'  => string|null,
     *   'last_seen_at'   => string|null,  // ISO8601 or null
     *   'last_seen_human'=> string|null,  // e.g. "2m ago"
     *   'error_label'    => string,       // human-readable error copy for UI
     * ]
     *
     * @var list<array<string, mixed>>
     */
    public array $peerStatuses = [];

    /**
     * Derived overall sync health.
     *
     * @var 'all_synced'|'syncing'|'offline'|'error'|'unknown'
     */
    public string $overallStatus = 'unknown';

    /**
     * Human-readable relative time for the most-recent sync across all peers.
     * Null when no peer has ever synced.
     */
    public ?string $lastSyncedHuman = null;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Hydrate per-peer statuses + overall status from SyncStatusService.
     *
     * Method DI only — no constructor. Clock is injected here for the relative
     * time derivation (noGlobalLaravelFunction guard).
     */
    public function mount(
        CurrentUser $currentUser,
        SyncStatusService $statusService,
        Clock $clock,
    ): void {
        $userId = $currentUser->user()->id;
        $now = $clock->now();

        $this->peerStatuses = $this->buildPeerViewModels($statusService->peerStatuses($userId), $now);
        $this->overallStatus = $statusService->overallStatus($userId);
        $this->lastSyncedHuman = $statusService->lastSyncedHuman($now, $userId);
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(ViewFactory $views): View
    {
        return $views->make('sync::livewire.sync-status-section');
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Map raw stdClass rows to safe wire-serialisable view-model arrays.
     *
     * @param  array<int, \stdClass> $rows
     * @param  CarbonImmutable       $now  Reference point for relative time.
     * @return list<array<string, mixed>>
     */
    private function buildPeerViewModels(array $rows, CarbonImmutable $now): array
    {
        $viewModels = [];

        foreach ($rows as $row) {
            $vars = get_object_vars($row);

            $peerDeviceId = is_string($vars['peer_device_id'] ?? null) ? $vars['peer_device_id'] : '';
            $status = is_string($vars['status'] ?? null) ? $vars['status'] : '';
            $errorMessage = is_string($vars['error_message'] ?? null) && $vars['error_message'] !== '' ? $vars['error_message'] : null;
            $lastSeenAt = is_string($vars['last_seen_at'] ?? null) && $vars['last_seen_at'] !== '' ? $vars['last_seen_at'] : null;

            $lastSeenHuman = null;
            if ($lastSeenAt !== null) {
                $lastSeenHuman = $this->humanRelativeTime($now, $lastSeenAt);
            }

            $errorLabel = $this->deriveErrorLabel($status, $errorMessage);

            $viewModels[] = [
                'peer_device_id' => $peerDeviceId,
                'status' => $status,
                'error_message' => $errorMessage,
                'last_seen_at' => $lastSeenAt,
                'last_seen_human' => $lastSeenHuman,
                'error_label' => $errorLabel,
            ];
        }

        return $viewModels;
    }

    /**
     * Derive a human-readable error label for a failed-status row.
     *
     * Maps known error_message prefixes to D-06-specified copy:
     *   - "can't reach peer" / connection-failure variants
     *   - "relay unreachable"
     *   - "handshake" / "verify" / "authentication" failures
     *
     * Returns empty string when the status is not 'failed'.
     */
    private function deriveErrorLabel(string $status, ?string $errorMessage): string
    {
        if ($status !== 'failed') {
            return '';
        }

        if ($errorMessage === null || $errorMessage === '') {
            return 'Connection failed';
        }

        $lower = strtolower($errorMessage);

        if (str_contains($lower, 'relay')) {
            return 'Relay unreachable';
        }

        if (str_contains($lower, 'handshake')
            || str_contains($lower, 'verify')
            || str_contains($lower, 'auth')
            || str_contains($lower, 'authentication')
        ) {
            return 'Handshake / verify failed';
        }

        if (str_contains($lower, 'connection')
            || str_contains($lower, 'connect')
            || str_contains($lower, 'reach')
            || str_contains($lower, 'timeout')
        ) {
            return "Can't reach peer";
        }

        return 'Connection failed';
    }

    /**
     * Compute a relative time string from an ISO8601 timestamp string.
     * Returns null if the timestamp cannot be parsed.
     */
    private function humanRelativeTime(CarbonImmutable $now, string $isoTimestamp): ?string
    {
        try {
            $past = CarbonImmutable::parse($isoTimestamp);
        } catch (\Throwable) {
            return null;
        }

        $absDiff = abs((int) $now->diffInSeconds($past, false));

        if ($absDiff < 60) {
            return 'just now';
        }

        if ($absDiff < 3600) {
            $minutes = (int) floor($absDiff / 60);

            return $minutes === 1 ? '1m ago' : "{$minutes}m ago";
        }

        if ($absDiff < 86400) {
            $hours = (int) floor($absDiff / 3600);

            return $hours === 1 ? '1h ago' : "{$hours}h ago";
        }

        $days = (int) floor($absDiff / 86400);

        return $days === 1 ? '1 day ago' : "{$days} days ago";
    }
}
