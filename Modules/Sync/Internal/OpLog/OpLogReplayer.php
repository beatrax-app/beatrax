<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Clock\HybridLogicalClock;

/**
 * LWW (last-writer-wins) op-log replayer.
 *
 * Sorts a merged set of OpLogEntry objects from all devices by HLC total
 * order, then applies the winning value per (table, pk, field) triple to
 * the real SQLite schema within a single DB transaction.
 *
 * WAVE 1 SKELETON: The sort and the transaction wrapper are in place;
 * the LWW resolution logic and the verify-before-apply gate are
 * implemented in Wave 2 (10-02). This file defines the fixed interface
 * that all four conflict-scenario tests in 10-01 Task 3 call.
 *
 * Cross-user discipline (T-10-02): every DB write must include
 * WHERE user_id = $userId. The BelongsToUser global scope does not fire
 * under queue/console, so the explicit filter is the primary guard —
 * same pattern as AnomalyEvaluator.
 *
 * $deviceKeys is an array<string, string> map of device-id → hex
 * Ed25519 public key. Injected at the container boundary (default: empty
 * map — no signature verification in the spike's non-signature tests).
 * Tests override by constructing OpLogReplayer directly with a throwaway
 * map built from sodium_crypto_sign_keypair().
 */
final readonly class OpLogReplayer
{
    /**
     * @param  DatabaseManager  $db  Raw DB access (bypasses Eloquent model events).
     * @param  array<string, string>  $deviceKeys  device-id => hex Ed25519 public key.
     */
    public function __construct(
        private DatabaseManager $db,
        private array $deviceKeys = [],
    ) {}

    /**
     * Replay a merged set of op-log entries against the real SQLite schema.
     *
     * Signature is fixed: two arguments — $entries and $userId.
     * Device public keys come from the constructor $deviceKeys map.
     *
     * @param  list<OpLogEntry>  $entries  Entries from all devices (any order).
     * @param  int  $userId  Scope all DB writes to this user.
     */
    public function replay(array $entries, int $userId): void
    {
        // Step 1: Sort by HLC total order (l, c, device_id) so later HLC
        // wins when we walk the sorted list in Wave 2.
        $sorted = $entries;
        usort(
            $sorted,
            fn (OpLogEntry $a, OpLogEntry $b): int => HybridLogicalClock::compare(
                $a->hlcL, $a->hlcC, $a->deviceId,
                $b->hlcL, $b->hlcC, $b->deviceId,
            ),
        );

        // $deviceKeys is the device-id → hex-pubkey map used by the
        // verify-before-apply gate implemented in Wave 2 (10-02).
        $deviceKeys = $this->deviceKeys;

        // Step 2: Apply within a single DB transaction.
        // Wave 1 skeleton — LWW resolution and verify-before-apply gate
        // are implemented in Wave 2 (10-02). The transaction wrapper is
        // in place so the interface is stable for the RED tests.
        $this->db->connection()->transaction(
            function () use ($sorted, $userId, $deviceKeys): void {
                // Wave 2 implements the LWW merge and DB writes here.
                // $sorted and $userId are available; $deviceKeys provides
                // public keys for the verify-before-apply gate.
                unset($sorted, $userId, $deviceKeys);
            },
        );
    }
}
