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

/**
 * Idempotent, resumable full-history initial-sync pull (R5 / D-03 / D-04,
 * MOBILE-01/MOBILE-02).
 *
 * `pull()` drives ONE bounded step of the Plan 05 transport
 * (`MobileSyncTriggerService::syncOnce()`, KEK-gated, LAN-then-relay) and,
 * after that step, persists the durable `mobile_sync_progress` cursor
 * (records_applied / records_expected / last_hlc_l / last_hlc_c / phase) —
 * NEVER a Livewire property or session value alone (RESEARCH Pattern 3 /
 * Pitfall 5). A cold-started process (an app-kill mid-pull, or the first-
 * attempt iOS Local Network Privacy denial from Plan 05) re-instantiates
 * this class with zero in-memory state and resumes exactly where the
 * durable cursor left off.
 *
 * ## Progress bookkeeping (watermark-resumed, no new dedup logic)
 *
 * Rather than trusting `syncOnce()`'s opaque bool alone for progress
 * display, `pull()` recomputes the TRUE local applied-count on every step
 * via `PeerCatchUpExchanger::opsAfterWatermark()` — the exact same
 * watermark-scoped local-op query the wire protocol itself uses — read
 * against the cursor's OWN persisted `last_hlc_l`/`last_hlc_c` (not the
 * device's write-only `hlc_clock_state`). This rides the op-log's existing
 * HLC-ordered, idempotent-on-replay storage (`OpLogReplayer`/
 * `SyncSession::receiveOps()`, unchanged) for correctness — no new
 * dedup/resume logic is invented here; a repeated `pull()` call over
 * already-counted entries advances nothing, because the watermark only
 * ever moves forward.
 *
 * `syncOnce() === true` is the sole completion signal: it means the FULL
 * bidirectional `PeerCatchUpExchanger` exchange (both `CATCH_UP_COMPLETE`
 * messages) finished in that step, so whatever is locally applied at that
 * moment IS the final `records_expected` (the phone has no way to learn
 * the peer's total record count ahead of a completed exchange — the wire
 * protocol carries only a per-response `frame_count`, not a running total).
 * `syncOnce() === false` (retryable) or `null` (KEK-absent, RESEARCH
 * Anti-Pattern #3 — skip, never cache the key) leave the cursor's
 * `records_expected` as a monotonically-growing best-effort estimate so
 * the UI's percent never regresses.
 *
 * All progress math is plain integers (no float).
 *
 * @internal Plan 08 — read by `SetupProgressScreen`. Already forward-
 *           registered by the Plan 01 `MobileServiceProvider`.
 */
final class InitialSyncPuller
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly MobileSyncTriggerService $trigger,
        private readonly DeviceIdentityLoader $identityLoader,
        private readonly DeviceRegistryService $registryService,
        private readonly PeerCatchUpExchanger $catchUp,
        private readonly TransportFramer $framer,
        private readonly Clock $clock,
        private readonly HistoryReprojector $reprojector,
    ) {}

    /**
     * Run ONE bounded pull step for $userId and persist the durable cursor.
     *
     * Safe to call repeatedly (e.g. from a Livewire `wire:poll` tick) —
     * once `phase` reaches `complete` this is a cheap idempotent no-op.
     *
     * @return array{records_applied: int, records_expected: ?int, percent: int, phase: string}
     */
    public function pull(
        int $userId,
        Session $session,
        ?string $lanHost = null,
        ?int $lanPort = null,
    ): array {
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            // RESEARCH Anti-Pattern #3: locked / no KEK / sync never enabled
            // — skip entirely. Data stays encrypted; the cursor is left
            // untouched (never advance progress on an attempt that never
            // happened).
            return $this->progress($userId);
        }

        $peerDeviceId = $this->resolvePeerDeviceId($userId, $identity->deviceId);
        if ($peerDeviceId === null) {
            // No confirmed peer yet — nothing to pull from.
            return $this->progress($userId);
        }

        $cursor = $this->loadOrCreateCursor($userId, $peerDeviceId);

        if ($cursor['phase'] === 'complete') {
            return $this->toProgressArray($cursor);
        }

        $result = $this->trigger->syncOnce($userId, $session, $lanHost, $lanPort);

        if ($result === null) {
            // KEK became unavailable mid-flow (race) — skip, no mutation.
            return $this->toProgressArray($cursor);
        }

        [$newlyApplied, $maxHlcL, $maxHlcC] = $this->countAppliedSince(
            $userId,
            $cursor['last_hlc_l'],
            $cursor['last_hlc_c'],
        );

        $recordsApplied = $cursor['records_applied'] + $newlyApplied;
        $lastHlcL = $newlyApplied > 0 ? $maxHlcL : $cursor['last_hlc_l'];
        $lastHlcC = $newlyApplied > 0 ? $maxHlcC : $cursor['last_hlc_c'];

        $phase = $result === true ? 'complete' : 'pulling';
        $recordsExpected = $result === true
            ? $recordsApplied
            : max($cursor['records_expected'] ?? 0, $recordsApplied);

        // Phase 15 import-join (Task 4, O1): the FIRST pull() step to
        // observe a non-empty GDK keyring (i.e. the desktop's delivered
        // epochs — possibly just installed by THIS very step's
        // LanSyncClient post-catch-up receive leg) re-projects the entire
        // persisted op-log via OpLogRebuilder::rebuild(), so any entry that
        // arrived (and was quarantined gdk_decrypt_failed) BEFORE the
        // keyring was populated now decrypts and projects. Runs AT MOST
        // ONCE per (user, peer) cursor — reprojected_at guards the repeat.
        // Runs synchronously, INLINE, before phase is allowed to read
        // 'complete' below — so a caller that observes phase === 'complete'
        // has ALWAYS already seen the re-projection finish (sequence:
        // sync step -> install epochs -> if newly decryptable, rebuild ->
        // then allow complete).
        $reprojectedAt = $cursor['reprojected_at'];
        if ($reprojectedAt === null && $this->keyringIsNonEmpty($userId)) {
            $this->reprojector->reproject($userId);
            $reprojectedAt = $this->clock->now()->toIso8601String();
        }

        $this->persistCursor($userId, $peerDeviceId, $recordsApplied, $recordsExpected, $lastHlcL, $lastHlcC, $phase, $reprojectedAt);

        return [
            'records_applied' => $recordsApplied,
            'records_expected' => $recordsExpected,
            'percent' => self::percentOf($recordsApplied, $recordsExpected),
            'phase' => $phase,
        ];
    }

    /**
     * True once `sync_encryption_state.current_epoch` is set for $userId —
     * a plain, non-secret integer pointer (T-14-01: never key material) the
     * SAME signal `GdkKeyringService::appendEpoch()`/`setCurrentEpoch()`
     * writes on every successful epoch install (self-mint OR a delivered
     * desktop wrap). Read directly via a raw table query rather than
     * `Modules\Sync\Internal\Crypto\GdkKeyringService` — that class is
     * off-limits to this module directly (BoundaryRule has no per-file
     * ignore); a bare table read of a public, non-secret column crosses no
     * module boundary.
     */
    private function keyringIsNonEmpty(int $userId): bool
    {
        $currentEpoch = $this->db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->value('current_epoch');

        return $currentEpoch !== null;
    }

    /**
     * Read the durable progress cursor WITHOUT driving a new pull step.
     *
     * `SetupProgressScreen::mount()` calls this so a cold-started process
     * renders at the TRUE resumed percent on its very first paint — never
     * a default-0 flash (RESEARCH Pattern 3 / Pitfall 5, UI-SPEC
     * Copywriting).
     *
     * @return array{records_applied: int, records_expected: ?int, percent: int, phase: string}
     */
    public function progress(int $userId): array
    {
        $row = $this->db->connection()
            ->table('mobile_sync_progress')
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->first();

        if ($row === null) {
            return ['records_applied' => 0, 'records_expected' => null, 'percent' => 0, 'phase' => 'pending'];
        }

        $recordsApplied = is_numeric($row->records_applied) ? (int) $row->records_applied : 0;
        $recordsExpected = is_numeric($row->records_expected) ? (int) $row->records_expected : null;
        $phase = is_string($row->phase) ? $row->phase : 'pending';

        return [
            'records_applied' => $recordsApplied,
            'records_expected' => $recordsExpected,
            'percent' => self::percentOf($recordsApplied, $recordsExpected),
            'phase' => $phase,
        ];
    }

    /**
     * Resolve the single confirmed non-self peer device_id for $userId
     * (single-household pairing, Phase 12 — mirrors
     * `LanSyncClient::resolvePeerStaticKeyHex()`'s own "first confirmed
     * non-self device" resolution; multi-peer selection is out of scope).
     */
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

    private function persistCursor(
        int $userId,
        string $peerDeviceId,
        int $recordsApplied,
        int $recordsExpected,
        int $lastHlcL,
        int $lastHlcC,
        string $phase,
        ?string $reprojectedAt,
    ): void {
        $now = $this->clock->now()->toIso8601String();

        // loadOrCreateCursor() above guarantees a row already exists for
        // this (user_id, peer_device_id) pair — a plain UPDATE, never a
        // second insert path (UNIQUE(user_id, peer_device_id) is the
        // one-durable-cursor-per-pair invariant, Plan 01 migration).
        $this->db->connection()
            ->table('mobile_sync_progress')
            ->where('user_id', $userId)
            ->where('peer_device_id', $peerDeviceId)
            ->update([
                'records_applied' => $recordsApplied,
                'records_expected' => $recordsExpected,
                'last_hlc_l' => $lastHlcL,
                'last_hlc_c' => $lastHlcC,
                'phase' => $phase,
                'reprojected_at' => $reprojectedAt,
                'updated_at' => $now,
            ]);
    }

    /**
     * Count how many LOCAL op_log_entries rows exist strictly after
     * ($lastHlcL, $lastHlcC) for $userId, and the max (hlc_l, hlc_c) among
     * them — via `PeerCatchUpExchanger::opsAfterWatermark()` (the same
     * watermark-scoped query the wire protocol itself uses) +
     * `TransportFramer::decode()`. A repeated call against an unchanged
     * watermark returns 0 — the watermark only ever advances, so this
     * never double-counts (no new dedup logic; rides the existing
     * HLC-ordered storage).
     *
     * @return array{0: int, 1: int, 2: int} [count, maxHlcL, maxHlcC]
     */
    private function countAppliedSince(int $userId, int $lastHlcL, int $lastHlcC): array
    {
        $frames = $this->catchUp->opsAfterWatermark($userId, $lastHlcL, $lastHlcC);

        $count = 0;
        $maxHlcL = $lastHlcL;
        $maxHlcC = $lastHlcC;

        foreach ($frames as $frame) {
            foreach ($this->framer->decode($frame) as $entry) {
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
     * @return array{records_applied: int, records_expected: ?int, percent: int, phase: string}
     */
    private function toProgressArray(array $cursor): array
    {
        return [
            'records_applied' => $cursor['records_applied'],
            'records_expected' => $cursor['records_expected'],
            'percent' => self::percentOf($cursor['records_applied'], $cursor['records_expected']),
            'phase' => $cursor['phase'],
        ];
    }

    /**
     * Plain-integer percent, clamped [0, 100]. Returns 0 when the expected
     * total is unknown or zero (never a division by zero, never a float).
     */
    private static function percentOf(int $applied, ?int $expected): int
    {
        if ($expected === null || $expected <= 0) {
            return 0;
        }

        return max(0, min(100, intdiv($applied * 100, $expected)));
    }
}
