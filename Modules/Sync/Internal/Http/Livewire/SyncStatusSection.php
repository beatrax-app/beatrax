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
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final class SyncStatusSection extends Component
{
    // Each element: peer_device_id (string), status
    // (connecting|handshaking|active|closed|failed), error_message
    // (string|null), last_seen_at (ISO8601|null), last_seen_human (e.g. "2m
    // ago"), error_label (human-readable error copy for UI).
    /**
     * @var list<array<string, mixed>>
     */
    public array $peerStatuses = [];

    /**
     * @var 'all_synced'|'syncing'|'offline'|'error'|'unknown'
     */
    public string $overallStatus = 'unknown';

    public ?string $lastSyncedHuman = null;

    // Clock is injected here for the relative time derivation
    // (noGlobalLaravelFunction guard).
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

    public function render(ViewFactory $views): View
    {
        return $views->make('sync::livewire.sync-status-section');
    }

    /**
     * @param  array<int, \stdClass>  $rows
     * @param  CarbonImmutable  $now  Reference point for relative time.
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

    // Maps known error_message prefixes ("can't reach peer" / connection
    // variants, "relay unreachable", "handshake"/"verify"/"authentication")
    // to human-readable copy. Returns empty string when status isn't 'failed'.
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
