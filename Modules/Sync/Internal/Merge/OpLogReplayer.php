<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\Strategies\GCounterStrategy;
use Modules\Sync\Internal\Merge\Strategies\LwwPerFieldStrategy;
use Modules\Sync\Internal\Merge\Strategies\MergeStrategyInterface;
use Modules\Sync\Internal\Merge\Strategies\OrSetStrategy;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

/**
 * Production LWW / CRDT op-log replayer with SE-07 fix, quarantine-never-throw,
 * config-driven strategy dispatch, and full I1/I2/signature security guards.
 *
 * ## What changed from the spike (Modules/Sync/Internal/OpLog/OpLogReplayer.php)
 *
 * 1. **SE-07 fix:** `decodeValue()` is now unconditional `json_decode()` — no
 *    raw-string fallback. SQL NULL = tombstone/clear sentinel; the JSON string
 *    `"null"` decodes to the PHP string `"null"`, not PHP null.
 *
 * 2. **Strategy dispatch:** Per (table, field), the winning value is computed by
 *    the MergeRulesRegistry-selected MergeStrategyInterface, not hard-coded LWW.
 *    LWW path stays behaviorally identical to the spike for transactions fields.
 *
 * 3. **Quarantine-never-throw:** Rejected ops (cross_user, missing_device_key,
 *    forged_signature, unknown_table, strategy_error, incomplete_create_row)
 *    write a structured row to op_log_quarantine and continue. Replay is
 *    deterministic — exceptions never propagate.
 *
 * 4. **Persist entries to op_log_entries:** The replayer persists in-memory
 *    OpLogEntry objects to op_log_entries (upsert-by-identity) so the log is
 *    durable. This is the production path — the spike only applied entries
 *    in-memory.
 *
 * ## Security invariants (preserved from spike — T-10-01, T-10-02)
 *
 * I1 — User-id filter before apply: entries whose userId !== $userId are
 *      quarantined ('cross_user') before any DB write.
 * I2 — WHERE user_id = $userId on every DB write: even if I1 were bypassed,
 *      no cross-user row would be touched.
 * Ed25519 gate: entries with no device key or a failing signature are
 *      quarantined ('missing_device_key' / 'forged_signature').
 *
 * ## decodeValue() is public (test-interface contract from 11-01-SUMMARY)
 *
 * AlwaysJsonWireContractTest calls $replayer->decodeValue($value) directly.
 * This method must be public so tests can verify the SE-07 fix in isolation.
 *
 * @param  array<string, string>  $deviceKeys  device-id => hex Ed25519 public key.
 */
final readonly class OpLogReplayer
{
    private DeviceKeySigner $signer;

    private MergeRulesRegistry $rules;

    /** @var array<string, MergeStrategyInterface> */
    private array $strategies;

    private Clock $clock;

    /**
     * @param  DatabaseManager  $db  Raw DB access (bypasses Eloquent model events).
     * @param  array<string, string>  $deviceKeys  device-id => hex Ed25519 public key.
     * @param  MergeRulesRegistry|null  $rules  Config-driven strategy registry (default: new instance).
     * @param  Clock|null  $wallClock  Clock for recorded_at timestamps (default: resolved from container).
     */
    public function __construct(
        private DatabaseManager $db,
        private array $deviceKeys = [],
        ?MergeRulesRegistry $rules = null,
        ?Clock $wallClock = null,
    ) {
        $this->signer = new DeviceKeySigner;
        $this->rules = $rules ?? new MergeRulesRegistry;
        $this->strategies = [
            'lww' => new LwwPerFieldStrategy,
            'g_counter' => new GCounterStrategy,
            'or_set' => new OrSetStrategy,
        ];
        // Wall clock: injected (for tests/DI) or fall back to Carbon directly.
        $this->clock = $wallClock ?? new class implements Clock
        {
            public function now(): CarbonImmutable
            {
                return CarbonImmutable::now();
            }
        };
    }

    /**
     * Decode an op-log entry value for writing to SQLite.
     *
     * SE-07 fix: unconditional json_decode — no raw-string fallback.
     * PHP null input means tombstone/clear sentinel → return null (write SQL NULL).
     * All other inputs are JSON-encoded by the producer, so json_decode is always valid.
     *
     * Round-trip examples:
     *   null     → null       (clear sentinel)
     *   "42"     → int 42
     *   '"foo"'  → string "foo"
     *   '"null"' → string "null"  (NOT PHP null — this is the SE-07 fix)
     *   '"007"'  → string "007"   (NOT int 7)
     *   '"1e3"'  → string "1e3"   (NOT float 1000.0)
     *
     * This method is PUBLIC so AlwaysJsonWireContractTest can call it directly
     * (test-interface contract established in 11-01-SUMMARY).
     */
    public function decodeValue(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Replay a merged set of op-log entries against the real SQLite schema.
     *
     * 1. Persist each entry to op_log_entries (upsert-by-identity).
     * 2. Filter + verify signatures (I1 + Ed25519 gate) — rejected → quarantine.
     * 3. Sort by HLC total order [l, c, device_id].
     * 4. Single-pass resolve per (table, pk, field) via strategy dispatch.
     * 5. Apply in a DB transaction (WHERE user_id = $userId on all writes — I2).
     *
     * @param  list<OpLogEntry>  $entries  Entries from all devices (any order).
     * @param  int  $userId  Scope all DB writes to this user.
     */
    public function replay(array $entries, int $userId): void
    {
        $now = $this->clock->now()->toDateTimeString();

        // Step 0: Persist entries to op_log_entries (upsert — replay is idempotent).
        foreach ($entries as $entry) {
            $this->db->connection()->table('op_log_entries')->updateOrInsert(
                [
                    'user_id' => $entry->userId,
                    'device_id' => $entry->deviceId,
                    'table_name' => $entry->table,
                    'pk' => (string) $entry->pk,
                    'field' => $entry->field,
                    'hlc_l' => $entry->hlcL,
                    'hlc_c' => $entry->hlcC,
                ],
                [
                    'op_type' => $entry->opType->value,
                    'value' => $entry->value,
                    'signature' => $entry->signature,
                    'recorded_at' => $now,
                ],
            );
        }

        // Step 1: filter to the scoped userId (I1 defense in depth) + Ed25519 gate.
        $verified = [];

        foreach ($entries as $entry) {
            if ($entry->userId !== $userId) {
                $this->quarantine($entry, 'cross_user', $now);

                continue;
            }

            $pubKeyHex = $this->deviceKeys[$entry->deviceId] ?? null;

            if ($pubKeyHex === null) {
                $this->quarantine($entry, 'missing_device_key', $now);

                continue;
            }

            $pubKeyBin = sodium_hex2bin($pubKeyHex);

            if (! $this->signer->verify($entry->signingPayload(), $entry->signature, $pubKeyBin)) {
                $this->quarantine($entry, 'forged_signature', $now);

                continue;
            }

            $verified[] = $entry;
        }

        // Step 2: sort verified entries by HLC total order [l, c, device_id].
        $sorted = $verified;
        usort(
            $sorted,
            fn (OpLogEntry $a, OpLogEntry $b): int => HybridLogicalClock::compare(
                $a->hlcL, $a->hlcC, $a->deviceId,
                $b->hlcL, $b->hlcC, $b->deviceId,
            ),
        );

        // Step 3: single pass — build resolved-value map, tombstone map, creates map.

        /** @var array<string, array<int|string, array<string, list<OpLogEntry>>>> $candidatesByField */
        $candidatesByField = [];  // [table][pk][field] => list<OpLogEntry> HLC-sorted ascending

        /** @var array<string, array<int|string, OpLogEntry>> $tombstones */
        $tombstones = [];  // [table][pk] => winning DELETE_TOMBSTONE entry

        /** @var array<string, array<int|string, array<string, list<OpLogEntry>>>> $creates */
        $creates = [];  // [table][pk][field] => list<OpLogEntry> for CREATE_ROW ops

        foreach ($sorted as $entry) {
            $pk = $entry->pk;

            if ($entry->opType === OpType::DeleteTombstone) {
                // Last tombstone in HLC sort order wins.
                $tombstones[$entry->table][$pk] = $entry;
            } elseif ($entry->opType === OpType::CreateRow) {
                $creates[$entry->table][$pk][$entry->field][] = $entry;
            } else {
                // SET: accumulate HLC-sorted candidates for strategy dispatch.
                $candidatesByField[$entry->table][$pk][$entry->field][] = $entry;
            }
        }

        // Step 4: apply within a single DB transaction.
        // Collect transfer deletions for pair-link cascade AFTER the transaction commits.
        /** @var list<array{partnerId: int, deletedType: string}> $pairCascades */
        $pairCascades = [];

        $this->db->connection()->transaction(
            function () use ($candidatesByField, $tombstones, $creates, $userId, $now, &$pairCascades): void {
                // --- CREATE_ROW path ---
                foreach ($creates as $table => $rows) {
                    foreach ($rows as $pk => $fields) {
                        $tomb = $tombstones[$table][$pk] ?? null;

                        if ($tomb !== null) {
                            // Find the max-HLC field entry in the create set.
                            $maxCreate = null;

                            foreach ($fields as $fieldEntries) {
                                foreach ($fieldEntries as $fieldEntry) {
                                    if ($maxCreate === null || HybridLogicalClock::compare(
                                        $fieldEntry->hlcL, $fieldEntry->hlcC, $fieldEntry->deviceId,
                                        $maxCreate->hlcL, $maxCreate->hlcC, $maxCreate->deviceId,
                                    ) > 0) {
                                        $maxCreate = $fieldEntry;
                                    }
                                }
                            }

                            if ($maxCreate !== null && HybridLogicalClock::compare(
                                $tomb->hlcL, $tomb->hlcC, $tomb->deviceId,
                                $maxCreate->hlcL, $maxCreate->hlcC, $maxCreate->deviceId,
                            ) >= 0) {
                                // Tombstone wins — do not create the row.
                                continue;
                            }
                        }

                        // Validate required columns are present.
                        $requiredCols = $this->rules->requiredCreateColumns($table);
                        $presentCols = array_keys($fields);
                        $missing = array_diff($requiredCols, $presentCols);

                        if ($missing !== []) {
                            // Build a synthetic entry to quarantine — use the first field entry.
                            $firstField = reset($fields);
                            if ($firstField !== false && $firstField !== []) {
                                $synth = $firstField[0];
                                $this->quarantine($synth, 'incomplete_create_row', $now);
                            }

                            continue;
                        }

                        // Assemble the INSERT payload: field => strategy-resolved value.
                        $payload = ['user_id' => $userId];

                        foreach ($fields as $field => $fieldEntries) {
                            try {
                                $strategyKey = $this->rules->strategyFor($table, $field);
                                $strategy = $this->strategies[$strategyKey] ?? $this->strategies['lww'];
                                $payload[$field] = $strategy->resolve($fieldEntries);
                            } catch (\Throwable) {
                                $this->quarantine($fieldEntries[0], 'strategy_error', $now);

                                continue 2;  // skip this entire row
                            }
                        }

                        // insertOrIgnore makes re-applying the same CREATE idempotent.
                        $this->db->connection()
                            ->table($table)
                            ->insertOrIgnore($payload);
                    }
                }

                // --- SET + DELETE_TOMBSTONE path ---
                foreach ($candidatesByField as $table => $rows) {
                    foreach ($rows as $pk => $fields) {
                        $tomb = $tombstones[$table][$pk] ?? null;

                        if ($tomb !== null) {
                            // Find the highest-HLC candidate entry across all fields.
                            $maxFieldEntry = null;

                            foreach ($fields as $fieldEntries) {
                                foreach ($fieldEntries as $fieldEntry) {
                                    if ($maxFieldEntry === null || HybridLogicalClock::compare(
                                        $fieldEntry->hlcL, $fieldEntry->hlcC, $fieldEntry->deviceId,
                                        $maxFieldEntry->hlcL, $maxFieldEntry->hlcC, $maxFieldEntry->deviceId,
                                    ) > 0) {
                                        $maxFieldEntry = $fieldEntry;
                                    }
                                }
                            }

                            // Delete-wins: tombstone HLC >= max field HLC (incl. equal tie).
                            if ($maxFieldEntry !== null && HybridLogicalClock::compare(
                                $tomb->hlcL, $tomb->hlcC, $tomb->deviceId,
                                $maxFieldEntry->hlcL, $maxFieldEntry->hlcC, $maxFieldEntry->deviceId,
                            ) >= 0) {
                                // Pair-link cascade: before deleting, check if this is a transfer row.
                                // ON DELETE SET NULL on pair_transaction_id means the partner survives
                                // with pair_transaction_id=NULL after the delete.
                                if ($table === 'transactions') {
                                    $txRow = $this->db->connection()
                                        ->table('transactions')
                                        ->where('id', $pk)
                                        ->where('user_id', $userId)
                                        ->first();

                                    if ($txRow !== null) {
                                        $txType = is_string($txRow->type ?? null) ? $txRow->type : null;
                                        $pairId = is_numeric($txRow->pair_transaction_id ?? null)
                                            ? (int) $txRow->pair_transaction_id
                                            : null;

                                        if ($pairId !== null && in_array($txType, ['transfer_in', 'transfer_out'], true)) {
                                            $pairCascades[] = [
                                                'partnerId' => $pairId,
                                                'deletedType' => $txType,
                                            ];
                                        }
                                    }
                                }

                                $this->db->connection()
                                    ->table($table)
                                    ->where('id', $pk)
                                    ->where('user_id', $userId)
                                    ->delete();

                                continue;
                            }
                        }

                        // Edit wins (or no tombstone) — apply each surviving field via strategy.
                        foreach ($fields as $field => $fieldEntries) {
                            try {
                                $strategyKey = $this->rules->strategyFor($table, $field);
                                $strategy = $this->strategies[$strategyKey] ?? $this->strategies['lww'];
                                $decodedValue = $strategy->resolve($fieldEntries);
                            } catch (\Throwable) {
                                $this->quarantine($fieldEntries[0], 'strategy_error', $now);

                                continue;
                            }

                            $this->db->connection()
                                ->table($table)
                                ->where('id', $pk)
                                ->where('user_id', $userId)
                                ->update([$field => $decodedValue]);
                        }
                    }
                }

                // Apply tombstones for (table, pk) pairs that had no SET entries.
                foreach ($tombstones as $table => $pks) {
                    foreach ($pks as $pk => $tomb) {
                        if (isset($candidatesByField[$table][$pk])) {
                            // Already handled in the SET loop above.
                            continue;
                        }

                        // Pair-link cascade: check before deleting a transfer row.
                        if ($table === 'transactions') {
                            $txRow = $this->db->connection()
                                ->table('transactions')
                                ->where('id', $pk)
                                ->where('user_id', $userId)
                                ->first();

                            if ($txRow !== null) {
                                $txType = is_string($txRow->type ?? null) ? $txRow->type : null;
                                $pairId = is_numeric($txRow->pair_transaction_id ?? null)
                                    ? (int) $txRow->pair_transaction_id
                                    : null;

                                if ($pairId !== null && in_array($txType, ['transfer_in', 'transfer_out'], true)) {
                                    $pairCascades[] = [
                                        'partnerId' => $pairId,
                                        'deletedType' => $txType,
                                    ];
                                }
                            }
                        }

                        // Pure tombstone (no competing SET) — delete the row.
                        $this->db->connection()
                            ->table($table)
                            ->where('id', $pk)
                            ->where('user_id', $userId)
                            ->delete();
                    }
                }
            },
        );

        // Step 5: pair-link cascade reclassification (D-08 / OQ-B).
        // After the merge transaction, detect orphaned transfer partners and
        // reclassify them. Each compensating op is also persisted to op_log_entries.
        foreach ($pairCascades as $cascade) {
            $partnerId = $cascade['partnerId'];
            $deletedType = $cascade['deletedType'];

            // Determine the reclassification target (OQ-B: transfer_in → income, transfer_out → expense).
            $newType = match ($deletedType) {
                'transfer_out' => 'income',   // partner was transfer_in, now orphaned → income
                'transfer_in' => 'expense',  // partner was transfer_out, now orphaned → expense
                default => null,
            };

            if ($newType === null) {
                continue;
            }

            // Verify the partner survives and is now orphaned (pair_transaction_id IS NULL).
            $partnerRow = $this->db->connection()
                ->table('transactions')
                ->where('id', $partnerId)
                ->where('user_id', $userId)
                ->first();

            if ($partnerRow === null || $partnerRow->pair_transaction_id !== null) {
                continue;
            }

            // Apply the reclassification.
            $this->db->connection()
                ->table('transactions')
                ->where('id', $partnerId)
                ->where('user_id', $userId)
                ->update(['type' => $newType]);

            // Persist the compensating op to op_log_entries.
            $this->db->connection()->table('op_log_entries')->insert([
                'user_id' => $userId,
                'device_id' => 'system-cascade',
                'table_name' => 'transactions',
                'pk' => (string) $partnerId,
                'field' => 'type',
                'op_type' => OpType::Set->value,
                'value' => json_encode($newType, JSON_THROW_ON_ERROR),
                'hlc_l' => 0,
                'hlc_c' => 0,
                'signature' => '',
                'recorded_at' => $now,
            ]);
        }
    }

    /**
     * Write a structured quarantine record for a skipped/rejected op.
     * Never throws. Replay is deterministic.
     *
     * @param  string  $reason  'cross_user'|'missing_device_key'|'forged_signature'|
     *                          'unknown_table'|'strategy_error'|'incomplete_create_row'
     */
    private function quarantine(OpLogEntry $entry, string $reason, string $now): void
    {
        try {
            $this->db->connection()->table('op_log_quarantine')->insert([
                'user_id' => $entry->userId,
                'table_name' => $entry->table,
                'pk' => (string) $entry->pk,
                'device_id' => $entry->deviceId,
                'reason' => $reason,
                'hlc_l' => $entry->hlcL,
                'hlc_c' => $entry->hlcC,
                'raw_value' => $entry->value,
                'created_at' => $now,
            ]);
        } catch (\Throwable) {
            // Quarantine write failure must not propagate — log and continue.
        }
    }
}
