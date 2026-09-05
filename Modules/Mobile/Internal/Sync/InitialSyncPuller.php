<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityState;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\HistoryReprojector;
use Modules\Sync\Public\Services\PeerLanAddressBook;
use Modules\Sync\Public\Services\WithheldHistoryReport;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/mobile/mobile-initial-sync-gate.md
 */
final readonly class InitialSyncPuller
{
    public function __construct(
        private DatabaseManager $db,
        private MobileSyncTriggerService $trigger,
        private DeviceIdentityLoader $identityLoader,
        private DeviceRegistryService $registryService,
        private PeerCatchUpExchanger $catchUp,
        private Clock $clock,
        private HistoryReprojector $reprojector,
        private PeerLanAddressBook $addresses,
        private WithheldHistoryReport $withheld,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{records_applied: int, records_expected: ?int, percent: int, phase: SyncPhase, blocked: ?SyncBlockedReason, withheld: int}
     */
    public function pull(
        int $userId,
        Session $session,
        ?string $lanHost = null,
        ?int $lanPort = null,
    ): array {
        $identity = $this->identityLoader->load($userId, $session);

        // A locked app-lock also returns a null identity, and reporting that as
        // a peer problem told the reader a healthy device had removed them —
        // terminal copy for a screen a PIN entry would have cleared. Asked only
        // once load() has already answered null, so an unlocked tick pays nothing.
        $locked = $identity === null
            && $this->identityLoader->state($userId, $session) === DeviceIdentityState::Locked;

        $peerDeviceId = $identity === null
            ? null
            : $this->resolvePeerDeviceId($userId, $identity->deviceId);

        if ($locked || $peerDeviceId === null) {
            // A peer that once confirmed and withdrew it reads identically to
            // one that never paired, and calling that "waiting for the other
            // device" left the screen turning on a pairing that cannot return.
            $blocked = match (true) {
                $locked => SyncBlockedReason::Locked,
                $this->peerRevokedUs($userId) => SyncBlockedReason::Revoked,
                default => SyncBlockedReason::NoPeer,
            };

            return [...$this->progress($userId), 'blocked' => $blocked];
        }

        $cursor = $this->loadOrCreateCursor($userId, $peerDeviceId);

        if ($cursor['phase'] === SyncPhase::Complete) {
            return $this->toProgressArray($cursor, $this->withheld->totalFor($userId));
        }

        return $this->advance($userId, $session, $peerDeviceId, $cursor, $lanHost, $lanPort);
    }

    /**
     * @param  array{records_applied: int, records_expected: ?int, last_hlc_l: int, last_hlc_c: int, phase: SyncPhase, reprojected_at: ?string, reproject_attempts: int}  $cursor
     * @return array{records_applied: int, records_expected: ?int, percent: int, phase: SyncPhase, blocked: ?SyncBlockedReason, withheld: int}
     */
    private function advance(
        int $userId,
        Session $session,
        string $peerDeviceId,
        array $cursor,
        ?string $lanHost,
        ?int $lanPort,
    ): array {
        // The caller's address comes from a scanned QR's relay endpoint and is
        // null for every other road in. Resolving it here instead is what makes
        // the typed-code arm work at all: this is the first point that knows
        // WHICH device to look for, so the browse can be aimed and remembered.
        if ($lanHost === null) {
            $located = $this->addresses->locate($userId, $peerDeviceId);
            $lanHost = $located['host'] ?? null;
            $lanPort = $located['port'] ?? $lanPort;
        }

        $result = $this->trigger->syncOnce($userId, $session, $lanHost, $lanPort);

        // Written by the exchange that just ran, or left standing by the last
        // one: what a peer is holding back for an author this device cannot
        // verify. Read on every tick so a hold that ends is reported ended.
        $withheldTotal = $this->withheld->totalFor($userId);

        if ($result === null) {
            return [...$this->toProgressArray($cursor, $withheldTotal), 'blocked' => SyncBlockedReason::Locked];
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
            && $result
            && $cursor['phase'] !== SyncPhase::Rebuilding;

        if ($announceRebuild) {
            $this->persistCursor($userId, $peerDeviceId, new MobileSyncCursor(
                recordsApplied: $recordsApplied,
                recordsExpected: max($cursor['records_expected'] ?? 0, $recordsApplied),
                lastHlcL: $lastHlcL,
                lastHlcC: $lastHlcC,
                phase: SyncPhase::Rebuilding,
                reprojectedAt: null,
            ));

            return [
                'records_applied' => $recordsApplied,
                'records_expected' => $recordsApplied,
                'percent' => 100,
                'phase' => SyncPhase::Rebuilding,
                'blocked' => SyncBlockedReason::Reprojecting,
                'withheld' => $withheldTotal,
            ];
        }

        // The first step to see a non-empty keyring re-projects what arrived
        // before the keys did, so those entries decrypt. At most once per
        // (user, peer) cursor.
        if ($reprojectedAt === null && $keysInstalled) {
            $reprojectedAt = $this->reproject($userId, $session, $peerDeviceId, $cursor['reproject_attempts']);
        }

        // Finishing the sync leg is necessary but not sufficient: without the
        // epochs and the re-projection, an import reports complete onto a
        // dashboard of rows it cannot decrypt.
        $isComplete = $result && $keysInstalled && $reprojectedAt !== null;

        $phase = $isComplete ? SyncPhase::Complete : SyncPhase::Pulling;

        // A finished transfer is not a whole history while a peer is holding
        // entries back, and an expected count equal to what landed renders
        // that hold as 100%. The denominator is everything this device knows
        // it is owed, which is what arrived plus what was declared held.
        $recordsExpected = $isComplete
            ? $recordsApplied + $withheldTotal
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
            'withheld' => $withheldTotal,
        ];
    }

    // The rows a keyless arrival quarantined, replayed through the same pass
    // the desktop's own recovery uses — not the whole persisted op log. The
    // whole-log rebuild cost 645 bytes per entry and exhausted the phone's
    // 128 MB ceiling at 200,000 of them, which is a year of one ledger.
    /**
     * @param  int  $attempts  Passes already started for this cursor; > 0 means one never returned.
     * @return string|null The stamp to persist, or null while the history is still unprojected.
     *
     * @link ../../../../.docs/features/mobile/mobile-initial-sync-gate.md#four-measured-costs-on-the-sync-path
     */
    private function reproject(int $userId, Session $session, string $peerDeviceId, int $attempts): ?string
    {
        if ($attempts > 0) {
            // Nothing else says so. A pass killed by memory exhaustion writes
            // no stamp and throws nothing catchable, so every later tick redid
            // the same doomed work behind a screen that looked merely slow.
            $this->logger->error('InitialSyncPuller: a previous history re-projection never returned; starting another.', [
                'user_id' => $userId,
                'peer_device_id' => $peerDeviceId,
                'attempts' => $attempts,
            ]);
        }

        $this->countReprojectAttempt($userId, $peerDeviceId, $attempts + 1);

        try {
            $rows = $this->reprojector->replayQuarantined($userId, $session, null, null);
        } catch (\Throwable $e) {
            // Leaving reprojected_at null keeps completion gated below,
            // so the next pull() retries instead of crashing the poll.
            $this->logger->error('InitialSyncPuller: history re-projection failed; will retry on the next pull.', [
                'user_id' => $userId,
                'exception' => $e,
            ]);

            return null;
        }

        $this->logger->info('InitialSyncPuller: re-projected the history that arrived before the keys.', [
            'user_id' => $userId,
            'peer_device_id' => $peerDeviceId,
            'rows' => $rows,
        ]);

        return Instant::zulu($this->clock->now());
    }

    private function countReprojectAttempt(int $userId, string $peerDeviceId, int $attempts): void
    {
        $this->db->connection()
            ->table('mobile_sync_progress')
            ->where('user_id', $userId)
            ->where('peer_device_id', $peerDeviceId)
            ->update(['reproject_attempts' => $attempts, 'updated_at' => Instant::zulu($this->clock->now())]);
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
     * @return array{records_applied: int, records_expected: ?int, percent: int, phase: SyncPhase, blocked: ?SyncBlockedReason, withheld: int}
     */
    public function progress(int $userId): array
    {
        $row = $this->db->connection()
            ->table('mobile_sync_progress')
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->first();

        if ($row === null) {
            return ['records_applied' => 0, 'records_expected' => null, 'percent' => 0, 'phase' => SyncPhase::Pending, 'blocked' => null, 'withheld' => 0];
        }

        $recordsApplied = is_numeric($row->records_applied) ? (int) $row->records_applied : 0;
        $recordsExpected = is_numeric($row->records_expected) ? (int) $row->records_expected : null;
        $phase = SyncPhase::fromStorage($row->phase);

        return [
            'records_applied' => $recordsApplied,
            'records_expected' => $recordsExpected,
            'percent' => self::percentOf($recordsApplied, $recordsExpected),
            'phase' => $phase,
            'blocked' => null,
            'withheld' => $this->withheld->totalFor($userId),
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

    private function resolvePeerDeviceId(int $userId, string $localDeviceId): ?string
    {
        $confirmed = $this->registryService->deviceKeys($userId);
        unset($confirmed[$localDeviceId]);

        $deviceIds = array_keys($confirmed);

        return $deviceIds[0] ?? null;
    }

    /**
     * @return array{records_applied: int, records_expected: ?int, last_hlc_l: int, last_hlc_c: int, phase: SyncPhase, reprojected_at: ?string, reproject_attempts: int}
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
                'phase' => SyncPhase::fromStorage($row->phase),
                'reprojected_at' => is_string($row->reprojected_at ?? null) ? $row->reprojected_at : null,
                'reproject_attempts' => is_numeric($row->reproject_attempts ?? null) ? (int) $row->reproject_attempts : 0,
            ];
        }

        $now = Instant::zulu($this->clock->now());
        $this->db->connection()->table('mobile_sync_progress')->insert([
            'user_id' => $userId,
            'peer_device_id' => $peerDeviceId,
            'records_expected' => null,
            'records_applied' => 0,
            'last_hlc_l' => 0,
            'last_hlc_c' => 0,
            'phase' => SyncPhase::Pending->value,
            'reprojected_at' => null,
            'reproject_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'records_applied' => 0,
            'records_expected' => null,
            'last_hlc_l' => 0,
            'last_hlc_c' => 0,
            'phase' => SyncPhase::Pending,
            'reprojected_at' => null,
            'reproject_attempts' => 0,
        ];
    }

    private function persistCursor(int $userId, string $peerDeviceId, MobileSyncCursor $cursor): void
    {
        $now = Instant::zulu($this->clock->now());

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
                'phase' => $cursor->phase->value,
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
        // Narrowed to the peer's own authorship: the watermark covers this
        // device's writes too, so a phone counted its own seeded rows as
        // records received from a desktop it had never reached. Asked as an
        // aggregate, because a poll tick cannot afford to hold the delta.
        $tally = $this->catchUp->tallyFromAuthorAfter($userId, $peerDeviceId, $lastHlcL, $lastHlcC);

        return [$tally['count'], $tally['hlc_l'], $tally['hlc_c']];
    }

    /**
     * @param  array{records_applied: int, records_expected: ?int, last_hlc_l: int, last_hlc_c: int, phase: SyncPhase}  $cursor
     * @param  int  $withheld  Entries a peer declared it is holding back, echoed through unchanged.
     * @return array{records_applied: int, records_expected: ?int, percent: int, phase: SyncPhase, blocked: ?SyncBlockedReason, withheld: int}
     */
    private function toProgressArray(array $cursor, int $withheld): array
    {
        return [
            'records_applied' => $cursor['records_applied'],
            'records_expected' => $cursor['records_expected'],
            'percent' => self::percentOf($cursor['records_applied'], $cursor['records_expected']),
            'phase' => $cursor['phase'],
            'blocked' => null,
            'withheld' => $withheld,
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
