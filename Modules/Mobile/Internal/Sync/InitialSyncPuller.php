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
 * @link ../../../../.docs/features/mobile/mobile-initial-sync-gate.md
 */
final class InitialSyncPuller
{
    // Without a state between "transfer finished" and "history rebuilt" the
    // rebuild ran inside the finishing tick and the step never rendered.
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

    // A cheap no-op once phase reaches complete, so a poll can keep calling.
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
            // A peer that once confirmed and withdrew it reads identically to
            // one that never paired, and calling that "waiting for the other
            // device" left the screen turning on a pairing that cannot return.
            return [
                ...$this->progress($userId),
                'blocked' => $this->peerRevokedUs($userId) ? SyncBlockedReason::Revoked : SyncBlockedReason::NoPeer,
            ];
        }

        $cursor = $this->loadOrCreateCursor($userId, $peerDeviceId);

        if ($cursor['phase'] === 'complete') {
            return $this->toProgressArray($cursor);
        }

        return $this->advance($userId, $session, $peerDeviceId, $cursor, $lanHost, $lanPort);
    }

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
            // The key became unavailable mid-flow: skip, mutate nothing.
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

        // Announce the rebuild on the tick BEFORE running it: re-projecting
        // blocks its own request, so running it in the tick that finished the
        // transfer made the screen jump to done past the slowest step.
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

        // The first step to see a non-empty keyring re-projects the whole
        // op-log, so entries quarantined before the keys arrived decrypt.
        // At most once per (user, peer) cursor.
        if ($reprojectedAt === null && $keysInstalled) {
            try {
                $this->reprojector->reproject($userId);
                $reprojectedAt = $this->clock->now()->toIso8601String();
            } catch (\Throwable $e) {
                // Leaving reprojected_at null keeps completion gated below,
                // so the next pull() retries instead of crashing the poll.
                $this->logger->error('InitialSyncPuller: history re-projection failed; will retry on the next pull.', [
                    'user_id' => $userId,
                    'exception' => $e,
                ]);
            }
        }

        // Finishing the sync leg is necessary but not sufficient: without the
        // epochs and the re-projection, an import reports complete onto a
        // dashboard of rows it cannot decrypt.
        $isComplete = $result === true && $keysInstalled && $reprojectedAt !== null;

        $phase = $isComplete ? 'complete' : 'pulling';
        $recordsExpected = $isComplete
            ? $recordsApplied
            : max($cursor['records_expected'] ?? 0, $recordsApplied);

        // An import exists because another device HAS data, so finishing with
        // nothing applied is an upstream defect. Nothing else says so: "0 of
        // 0" on a complete screen looks like a sync with nothing to carry.
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

        // Names what the pull is waiting on, so a stall reads as a stall
        // rather than as an ordinary slow sync.
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

    // A raw column read rather than GdkKeyringService, which is off-limits to
    // this module: current_epoch is a public, non-secret integer pointer.
    private function keyringIsNonEmpty(int $userId): bool
    {
        $currentEpoch = $this->db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->value('current_epoch');

        return $currentEpoch !== null;
    }

    // Reads the durable cursor without driving a step, so a cold-started
    // process paints the true resumed percent instead of flashing 0.
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

    // Paired but no longer confirmed means revoked: LanSyncClient clears
    // confirmed_at when the other side says it no longer knows this device.
    // A device that never paired has no row at all — "not yet", not "no more".
    private function peerRevokedUs(int $userId): bool
    {
        return $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 0)
            ->whereNotNull('paired_at')
            ->whereNull('confirmed_at')
            ->exists();
    }

    // Single-household pairing: the one confirmed non-self device.
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

        // loadOrCreateCursor() guarantees the row exists, so this stays a
        // plain UPDATE rather than a second insert path.
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

    // The watermark only ever advances, so a repeated call against an
    // unchanged one returns 0 and nothing is ever double-counted.
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
                // The watermark covers this device's own writes too, so a
                // phone counted its own seeded rows as records received from
                // a desktop it had never actually reached.
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

    private static function percentOf(int $applied, ?int $expected): int
    {
        if ($expected === null || $expected <= 0) {
            return 0;
        }

        return max(0, min(100, intdiv($applied * 100, $expected)));
    }
}
