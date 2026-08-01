<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
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
                $lastSeenHuman = SyncStatusService::relativeTime($now, $lastSeenAt);
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

    // Ordered most specific first: a message naming both the relay and a
    // timeout is reported as a relay problem. Each row carries a set of
    // needles, since the same failure reaches us phrased several ways. The
    // 'label' is a translation key resolved via Lang::get in deriveErrorLabel.
    /**
     * @var list<array{needles: list<string>, label: string}>
     */
    private const ERROR_LABELS = [
        ['needles' => ['relay'], 'label' => 'sync::status.labels.relay_unreachable'],
        // 'authentication' is deliberately absent: it contains 'auth', so a
        // needle for it could never match anything the shorter one missed.
        ['needles' => ['handshake', 'verify', 'auth'], 'label' => 'sync::status.labels.handshake_failed'],
        ['needles' => ['connection', 'connect', 'reach', 'timeout'], 'label' => 'sync::status.labels.cannot_reach_peer'],
    ];

    // Empty string when the row did not fail, so the view renders no label
    // rather than an empty one. An unrecognised message — including none at
    // all — still says the connection failed, because it did.
    private function deriveErrorLabel(string $status, ?string $errorMessage): string
    {
        if ($status !== 'failed') {
            return '';
        }

        $lower = strtolower($errorMessage ?? '');
        foreach (self::ERROR_LABELS as $candidate) {
            foreach ($candidate['needles'] as $needle) {
                if (str_contains($lower, $needle)) {
                    return Lang::get($candidate['label']);
                }
            }
        }

        return Lang::get('sync::status.labels.connection_failed');
    }
}
