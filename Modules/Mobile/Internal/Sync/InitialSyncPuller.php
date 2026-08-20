<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\HistoryReprojector;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final class InitialSyncPuller
{
    // The cursor state between "transfer finished" and "history rebuilt".
    // Without it the rebuild ran inside the tick that finished the transfer
    // and the step was never rendered.
    private const string PHASE_REBUILDING = 'rebuilding';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly MobileSyncTriggerService $trigger,
        private readonly DeviceIdentityLoader $identityLoader,
        private readonly DeviceRegistryService $registryService,
        private readonly PeerCatchUpExchanger $catchUp,
        private readonly TransportFramer $framer,
        private readonly Clock $clock,
        private readonly HistoryReprojector $reprojector,
        private readonly LoggerInterface $logger,
    ) {}

    // Safe to call repeatedly (e.g. from a Livewire wire:poll tick) -
    // once phase reaches complete this is a cheap idempotent no-op.
    /**
     * @return array{records_applied: int, records_expected: ?int, percent: int, phase: string, blocked: ?SyncBlockedReason}
     */
    public function pull(
        int $userId,
        Session $session,
        ?string $lanHost = null,
        ?int $lanPort = null,
    ): array {
        $identity = $this->identityLoader->load($userId, $session);
        $peerDeviceId = $identity === null
            ? null
            : $this->resolvePeerDeviceId($userId, $identity->deviceId);

        if ($peerDeviceId === null) {
            // Locked / no key / sync never enabled, or no confirmed peer
            // yet - skip entirely. Data stays encrypted; the cursor is left
            // untouched.
            return [...$this->progress($userId), 'blocked' => SyncBlockedReason::NoPeer];
        }

        $cursor = $this->loadOrCreateCursor($userId, $peerDeviceId);

        if ($cursor['phase'] === 'complete') {
            return $this->toProgressArray($cursor);
        }

        return $this->advance($userId, $session, $peerDeviceId, $cursor, $lanHost, $lanPort);
    }

    // Drives one sync step against a resolved, not-yet-complete cursor and
    // persists the advanced state. Split from pull() so the identity/peer/
    // complete guards stay a flat prelude rather than nesting the whole
    // apply-and-persist body behind them.
    /**
     * @param  array{records_applied: int, records_expected: ?int, last_hlc_l: int, last_hlc_c: int, phase: string, reprojected_at: ?string}  $cursor
     * @return array{records_applied: int, records_expected: ?int, percent: int, phase: string, blocked: ?SyncBlockedReason}
     */
    private function advance(
        int $userId,
        Session $session,
        string $peerDeviceId,
        array $cursor,
        ?string $lanHost,
        ?int $lanPort,
    ): array {
        $result = $this->trigger->syncOnce($userId, $session, $lanHost, $lanPort);

        if ($result === null) {
            // The key became unavailable mid-flow (race) - skip, no
            // mutation.
            return [...$this->toProgressArray($cursor), 'blocked' => SyncBlockedReason::Locked];
        }

        [$newlyApplied, $maxHlcL, $maxHlcC] = $this->countAppliedSince(
            $userId,
            $cursor['last_hlc_l'],
            $cursor['last_hlc_c'],
            $peerDeviceId,
        );

        $recordsApplied = $cursor['records_applied'] + $newlyApplied;
        $lastHlcL = $newlyApplied > 0 ? $maxHlcL : $cursor['last_hlc_l'];
        $lastHlcC = $newlyApplied > 0 ? $maxHlcC : $cursor['last_hlc_c'];

        $keysInstalled = $this->keyringIsNonEmpty($userId);

        // Announce the rebuild on the tick BEFORE running it. Re-projecting
        // blocks the request it runs in, so doing it in the tick that
        // finished the transfer made the screen jump straight to done,
        // never showing the step that actually takes the time.
        $reprojectedAt = $cursor['reprojected_at'];
        $announceRebuild = $reprojectedAt === null
            && $keysInstalled
            && $result === true
            && $cursor['phase'] !== self::PHASE_REBUILDING;

        if ($announceRebuild) {
            $this->persistCursor($userId, $peerDeviceId, new MobileSyncCursor(
                recordsApplied: $recordsApplied,
                recordsExpected: max($cursor['records_expected'] ?? 0, $recordsApplied),
                lastHlcL: $lastHlcL,
                lastHlcC: $lastHlcC,
                phase: self::PHASE_REBUILDING,
                reprojectedAt: null,
            ));

            return [
                'records_applied' => $recordsApplied,
                'records_expected' => $recordsApplied,
                'percent' => 100,
                'phase' => self::PHASE_REBUILDING,
                'blocked' => SyncBlockedReason::Reprojecting,
            ];
        }

        // The FIRST pull() step to observe a non-empty keyring re-projects
        // the entire persisted op-log so any entry quarantined before the
        // keyring was populated now decrypts and projects. Runs at most
        // once per (user, peer) cursor, synchronously before completion.
        if ($reprojectedAt === null && $keysInstalled) {
            try {
                $this->reprojector->reproject($userId);
                $reprojectedAt = $this->clock->now()->toIso8601String();
            } catch (\Throwable $e) {
                // A re-projection failure must not crash the setup-screen
                // poll. Log and leave reprojected_at null; completion
                // stays gated on it below, so the next pull() retries.
                $this->logger->error('InitialSyncPuller: history re-projection failed; will retry on the next pull.', [
                    'user_id' => $userId,
                    'exception' => $e,
                ]);
            }
        }

        // Finishing the sync leg is necessary but not sufficient for an
        // import - completion also requires the desktop's epochs
        // installed AND the history re-projected, else a relay-only or
        // not-yet-delivered import would report complete prematurely.
        $isComplete = $result === true && $keysInstalled && $reprojectedAt !== null;

        $phase = $isComplete ? 'complete' : 'pulling';
        $recordsExpected = $isComplete
            ? $recordsApplied
            : max($cursor['records_expected'] ?? 0, $recordsApplied);

        // An import exists because another device HAS data, so finishing one
        // with nothing applied is a defect upstream, not a quiet success.
        // Nothing else says so: "0 of 0" on a complete screen is
        // indistinguishable from a sync that had nothing to carry.
        if ($isComplete && $recordsApplied === 0) {
            $this->logger->warning('InitialSyncPuller: import completed without applying a single record.', [
                'user_id' => $userId,
                'peer_device_id' => $peerDeviceId,
            ]);
        }

        $this->persistCursor($userId, $peerDeviceId, new MobileSyncCursor(
            recordsApplied: $recordsApplied,
            recordsExpected: $recordsExpected,
            lastHlcL: $lastHlcL,
            lastHlcC: $lastHlcC,
            phase: $phase,
            reprojectedAt: $reprojectedAt,
        ));

        // A silent screen is indistinguishable from a working one: this
        // names what the pull is waiting on so a stall is legible instead of
        // looking like an ordinary slow sync.
        $blocked = match (true) {
            $result === false => SyncBlockedReason::Unreachable,
            ! $keysInstalled => SyncBlockedReason::NoKeys,
            $reprojectedAt === null => SyncBlockedReason::Reprojecting,
            default => null,
        };

        return [
            'records_applied' => $recordsApplied,
            'records_expected' => $recordsExpected,
            'percent' => self::percentOf($recordsApplied, $recordsExpected),
            'phase' => $phase,
            'blocked' => $blocked,
        ];
    }

    // A plain, non-secret integer pointer - read directly via a raw
    // table query rather than GdkKeyringService (off-limits to this
    // module directly); a bare read of a public, non-secret column
    // crosses no module boundary.
    private function keyringIsNonEmpty(int $userId): bool
    {
        $currentEpoch = $this->db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->value('current_epoch');

        return $currentEpoch !== null;
    }

    // Reads the durable progress cursor without driving a new pull step,
    // so a cold-started process renders at the true resumed percent on
    // its very first paint - never a default-0 flash.
    /**
     * @return array{records_applied: int, records_expected: ?int, percent: int, phase: string, blocked: ?SyncBlockedReason}
     */
    public function progress(int $userId): array
    {
        $row = $this->db->connection()
            ->table('mobile_sync_progress')
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->first();

        if ($row === null) {
            return ['records_applied' => 0, 'records_expected' => null, 'percent' => 0, 'phase' => 'pending', 'blocked' => null];
        }

        $recordsApplied = is_numeric($row->records_applied) ? (int) $row->records_applied : 0;
        $recordsExpected = is_numeric($row->records_expected) ? (int) $row->records_expected : null;
        $phase = is_string($row->phase) ? $row->phase : 'pending';

        return [
            'records_applied' => $recordsApplied,
            'records_expected' => $recordsExpected,
            'percent' => self::percentOf($recordsApplied, $recordsExpected),
            'phase' => $phase,
            'blocked' => null,
        ];
    }

    // Resolves the single confirmed non-self peer device_id (single-
    // household pairing); multi-peer selection is out of scope.
    private function resolvePeerDeviceId(int $userId, string $localDeviceId): ?string
    {
        $confirmed = $this->registryService->deviceKeys($userId);
        unset($confirmed[$localDeviceId]);

        $deviceIds = array_keys($confirmed);

        return $deviceIds[0] ?? null;
    }

    /**
     * @return array{records_applied: int, records_expected: ?int, last_hlc_l: int, last_hlc_c: int, phase: string, reprojected_at: ?string}
     */
    private function loadOrCreateCursor(int $userId, string $peerDeviceId): array
    {
        $row = $this->db->connection()
            ->table('mobile_sync_progress')
            ->where('user_id', $userId)
            ->where('peer_device_id', $peerDeviceId)
            ->first();

        if ($row !== null) {
            return [
                'records_applied' => is_numeric($row->records_applied) ? (int) $row->records_applied : 0,
                'records_expected' => is_numeric($row->records_expected) ? (int) $row->records_expected : null,
                'last_hlc_l' => is_numeric($row->last_hlc_l) ? (int) $row->last_hlc_l : 0,
                'last_hlc_c' => is_numeric($row->last_hlc_c) ? (int) $row->last_hlc_c : 0,
                'phase' => is_string($row->phase) ? $row->phase : 'pending',
                'reprojected_at' => is_string($row->reprojected_at ?? null) ? $row->reprojected_at : null,
            ];
        }

        $now = $this->clock->now()->toIso8601String();
        $this->db->connection()->table('mobile_sync_progress')->insert([
            'user_id' => $userId,
            'peer_device_id' => $peerDeviceId,
            'records_expected' => null,
            'records_applied' => 0,
            'last_hlc_l' => 0,
            'last_hlc_c' => 0,
            'phase' => 'pending',
            'reprojected_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'records_applied' => 0,
            'records_expected' => null,
            'last_hlc_l' => 0,
            'last_hlc_c' => 0,
            'phase' => 'pending',
            'reprojected_at' => null,
        ];
    }

    private function persistCursor(int $userId, string $peerDeviceId, MobileSyncCursor $cursor): void
    {
        $now = $this->clock->now()->toIso8601String();

        // loadOrCreateCursor() above guarantees a row already exists for
        // this (user_id, peer_device_id) pair - a plain UPDATE, never a
        // second insert path.
        $this->db->connection()
            ->table('mobile_sync_progress')
            ->where('user_id', $userId)
            ->where('peer_device_id', $peerDeviceId)
            ->update([
                'records_applied' => $cursor->recordsApplied,
                'records_expected' => $cursor->recordsExpected,
                'last_hlc_l' => $cursor->lastHlcL,
                'last_hlc_c' => $cursor->lastHlcC,
                'phase' => $cursor->phase,
                'reprojected_at' => $cursor->reprojectedAt,
                'updated_at' => $now,
            ]);
    }

    // Counts how many entries FROM THE PEER exist strictly after ($lastHlcL,
    // $lastHlcC), and the max (hlc_l, hlc_c) among them. A repeated call
    // against an unchanged watermark returns 0 - the watermark only ever
    // advances, so this never double-counts.
    /**
     * @return array{0: int, 1: int, 2: int} [count, maxHlcL, maxHlcC]
     */
    private function countAppliedSince(int $userId, int $lastHlcL, int $lastHlcC, string $peerDeviceId): array
    {
        $frames = $this->catchUp->opsAfterWatermark($userId, $lastHlcL, $lastHlcC);

        $count = 0;
        $maxHlcL = $lastHlcL;
        $maxHlcC = $lastHlcC;

        foreach ($frames as $frame) {
            foreach ($this->framer->decode($frame) as $entry) {
                // Only what the PEER sent counts as progress. The watermark
                // covers this device's own writes too, so a phone reported its
                // own seeded rows as records received from a desktop it had
                // never actually reached.
                if ($entry->deviceId !== $peerDeviceId) {
                    continue;
                }

                $count++;

                if ($entry->hlcL > $maxHlcL || ($entry->hlcL === $maxHlcL && $entry->hlcC > $maxHlcC)) {
                    $maxHlcL = $entry->hlcL;
                    $maxHlcC = $entry->hlcC;
                }
            }
        }

        return [$count, $maxHlcL, $maxHlcC];
    }

    /**
     * @param  array{records_applied: int, records_expected: ?int, last_hlc_l: int, last_hlc_c: int, phase: string}  $cursor
     * @return array{records_applied: int, records_expected: ?int, percent: int, phase: string, blocked: ?SyncBlockedReason}
     */
    private function toProgressArray(array $cursor): array
    {
        return [
            'records_applied' => $cursor['records_applied'],
            'records_expected' => $cursor['records_expected'],
            'percent' => self::percentOf($cursor['records_applied'], $cursor['records_expected']),
            'phase' => $cursor['phase'],
            'blocked' => null,
        ];
    }

    // Plain-integer percent, clamped [0, 100]. Returns 0 when the
    // expected total is unknown or zero (never a division by zero).
    private static function percentOf(int $applied, ?int $expected): int
    {
        if ($expected === null || $expected <= 0) {
            return 0;
        }

        return max(0, min(100, intdiv($applied * 100, $expected)));
    }
}
