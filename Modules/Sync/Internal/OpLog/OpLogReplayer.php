<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

/**
 * LWW (last-writer-wins) op-log replayer.
 *
 * Sorts a merged set of OpLogEntry objects from all devices by HLC total
 * order, then applies the winning value per (table, pk, field) triple to
 * the real SQLite schema within a single DB transaction.
 *
 * ## Algorithm (single-pass, HLC-sorted)
 *
 * 1. Filter entries to the scoped $userId (defense in depth — Pitfall 2).
 * 2. Verify Ed25519 signature of each entry against the constructor-injected
 *    $deviceKeys map. Skip entries that fail or have no key on record.
 * 3. Sort by HLC total order [l, c, device_id] so later ops overwrite earlier.
 * 4. Walk the sorted list once:
 *    - SET ops: record last-seen entry per (table, pk, field) triple.
 *    - DELETE_TOMBSTONE ops: record last-seen tombstone per (table, pk).
 * 5. Apply within a single DB transaction:
 *    - For each (table, pk): if a tombstone exists AND its HLC >= the highest
 *      field-SET HLC for that row, DELETE the row (delete-wins, including equal
 *      tie — this is the policy decision noted in the SUMMARY).
 *    - Otherwise: UPDATE each surviving field with its winning value.
 *    - All writes include WHERE user_id = ? (T-10-02 guard).
 *
 * ## CREATE_ROW handling
 *
 * A CreateRow entry carries the target table, pk, field, and value for each
 * column that must be set. The replayer collects all CreateRow ops for a given
 * (table, pk), assembles them into a single INSERT payload, and executes an
 * insertOrIgnore so re-applying the same op is idempotent.
 *
 * ## Cross-user discipline (T-10-02)
 *
 * Every DB write includes WHERE user_id = $userId. The BelongsToUser global
 * scope does not fire under queue/console, so the explicit filter is the
 * primary guard — same pattern as AnomalyEvaluator.
 *
 * $deviceKeys is an array<string, string> map of device-id → hex Ed25519
 * public key. Injected at the container boundary (default: empty map — no
 * entries pass the signature gate; tests inject throwaway maps from
 * sodium_crypto_sign_keypair()).
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
        $signer = new DeviceKeySigner;
        $deviceKeys = $this->deviceKeys;

        // Step 1: filter to the scoped userId (defense in depth — Pitfall 2).
        // Also verify each entry's Ed25519 signature (T-10-01 gate).
        // Entries with no device key on record, or a failing signature, are skipped.
        $verified = [];

        foreach ($entries as $entry) {
            if ($entry->userId !== $userId) {
                // Cross-user entry supplied to this replay scope — skip silently.
                continue;
            }

            $pubKeyHex = $deviceKeys[$entry->deviceId] ?? null;

            if ($pubKeyHex === null) {
                // No key on record for this device — skip (T-10-01).
                continue;
            }

            $pubKeyBin = sodium_hex2bin($pubKeyHex);

            if (! $signer->verify($entry->signingPayload(), $entry->signature, $pubKeyBin)) {
                // Signature fails Ed25519 verification — skip forged/tampered entry.
                continue;
            }

            $verified[] = $entry;
        }

        // Step 2: sort verified entries by HLC total order [l, c, device_id].
        // Walking sorted order means a later index always has a higher or equal HLC,
        // so the last assignment to $resolved[table][pk][field] is the winner.
        $sorted = $verified;
        usort(
            $sorted,
            fn (OpLogEntry $a, OpLogEntry $b): int => HybridLogicalClock::compare(
                $a->hlcL, $a->hlcC, $a->deviceId,
                $b->hlcL, $b->hlcC, $b->deviceId,
            ),
        );

        // Step 3: single pass — build resolved-value map + tombstone map.
        // The sort guarantees later entries overwrite earlier ones naturally.

        /** @var array<string, array<int|string, array<string, OpLogEntry>>> $resolved */
        $resolved = [];  // [table][pk][field] => winning SET entry

        /** @var array<string, array<int|string, OpLogEntry>> $tombstones */
        $tombstones = [];  // [table][pk] => winning DELETE_TOMBSTONE entry

        /** @var array<string, array<int|string, array<string, OpLogEntry>>> $creates */
        $creates = [];  // [table][pk][field] => CREATE_ROW field entry

        foreach ($sorted as $entry) {
            $pk = $entry->pk;

            if ($entry->opType === OpType::DeleteTombstone) {
                // Tombstone: record the latest (by sort position, so last wins).
                $tombstones[$entry->table][$pk] = $entry;
            } elseif ($entry->opType === OpType::CreateRow) {
                // CREATE_ROW: accumulate fields to assemble a single INSERT payload.
                $creates[$entry->table][$pk][$entry->field] = $entry;
            } else {
                // SET: overwrite with each later HLC (last wins due to sort).
                $resolved[$entry->table][$pk][$entry->field] = $entry;
            }
        }

        // Step 4: apply within a single DB transaction.
        $this->db->connection()->transaction(
            function () use ($resolved, $tombstones, $creates, $userId): void {
                // --- CREATE_ROW path ---
                // Assemble all field ops for each (table, pk) into a single insertOrIgnore.
                foreach ($creates as $table => $rows) {
                    foreach ($rows as $pk => $fields) {
                        // Check whether tombstone beats the CREATE (delete-wins).
                        $tomb = $tombstones[$table][$pk] ?? null;

                        if ($tomb !== null) {
                            // Find the max-HLC field in the create set.
                            $maxCreate = null;

                            foreach ($fields as $fieldEntry) {
                                if ($maxCreate === null || HybridLogicalClock::compare(
                                    $fieldEntry->hlcL, $fieldEntry->hlcC, $fieldEntry->deviceId,
                                    $maxCreate->hlcL, $maxCreate->hlcC, $maxCreate->deviceId,
                                ) > 0) {
                                    $maxCreate = $fieldEntry;
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

                        // Assemble the INSERT payload: field => decoded value.
                        $payload = ['user_id' => $userId];

                        foreach ($fields as $field => $entry) {
                            $payload[$field] = $entry->value !== null
                                ? $this->decodeValue($entry->value)
                                : null;
                        }

                        // insertOrIgnore makes re-applying the same CREATE idempotent.
                        $this->db->connection()
                            ->table($table)
                            ->insertOrIgnore($payload);
                    }
                }

                // --- SET + DELETE_TOMBSTONE path ---
                foreach ($resolved as $table => $rows) {
                    foreach ($rows as $pk => $fields) {
                        $tomb = $tombstones[$table][$pk] ?? null;

                        if ($tomb !== null) {
                            // Find the highest-HLC field-SET entry for this row.
                            $maxFieldEntry = null;

                            foreach ($fields as $fieldEntry) {
                                if ($maxFieldEntry === null || HybridLogicalClock::compare(
                                    $fieldEntry->hlcL, $fieldEntry->hlcC, $fieldEntry->deviceId,
                                    $maxFieldEntry->hlcL, $maxFieldEntry->hlcC, $maxFieldEntry->deviceId,
                                ) > 0) {
                                    $maxFieldEntry = $fieldEntry;
                                }
                            }

                            // Delete-wins: tombstone HLC >= max field HLC (including equal tie).
                            // This is the policy decision documented in FINDINGS: on an equal-HLC
                            // tombstone-vs-SET tie, delete wins. Rationale: resurecting a deleted
                            // row is worse UX than losing a concurrent edit when HLC ordering is
                            // ambiguous.
                            if ($maxFieldEntry !== null && HybridLogicalClock::compare(
                                $tomb->hlcL, $tomb->hlcC, $tomb->deviceId,
                                $maxFieldEntry->hlcL, $maxFieldEntry->hlcC, $maxFieldEntry->deviceId,
                            ) >= 0) {
                                // Tombstone wins — physically delete the row.
                                $this->db->connection()
                                    ->table($table)
                                    ->where('id', $pk)
                                    ->where('user_id', $userId)
                                    ->delete();

                                continue;
                            }
                        }

                        // Edit wins (or no tombstone) — apply each surviving field update.
                        foreach ($fields as $field => $entry) {
                            // Decode the stored value before writing.
                            // $entry->value is ?string; null means SET NULL.
                            $decodedValue = $entry->value !== null
                                ? $this->decodeValue($entry->value)
                                : null;

                            $this->db->connection()
                                ->table($table)
                                ->where('id', $pk)
                                ->where('user_id', $userId)
                                ->update([$field => $decodedValue]);
                        }
                    }
                }

                // Apply tombstones for (table, pk) pairs that had no SET entries.
                // These are pure-delete ops with no concurrent edits in this batch.
                foreach ($tombstones as $table => $pks) {
                    foreach ($pks as $pk => $tomb) {
                        if (isset($resolved[$table][$pk])) {
                            // Already handled in the SET loop above.
                            continue;
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
    }

    /**
     * Decode an op-log entry value for writing to SQLite.
     *
     * Values in OpLogEntry::$value are stored as plain strings (or
     * JSON-encoded strings for complex types). The spike stores integer
     * IDs as their string representation (e.g. "42") and plain text as
     * raw strings (e.g. "edited"). json_decode handles numeric strings
     * correctly (returns int); for non-JSON strings it returns null, so
     * we fall back to the raw string in that case.
     *
     * Encoding contract:
     * - Numeric category IDs: stored as "42" → json_decode → int 42. ✓
     * - Plain strings: stored as "edited" → json_decode returns null → raw string "edited". ✓
     * - Explicit null sentinel: $value === null (not the string "null") → handled by callers.
     */
    private function decodeValue(string $value): mixed
    {
        $decoded = json_decode($value, true);

        // json_decode returns null for non-JSON input (e.g. "edited").
        // In that case, use the raw string unchanged.
        if ($decoded === null && $value !== 'null') {
            return $value;
        }

        return $decoded;
    }
}
