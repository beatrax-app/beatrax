<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
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
 * 5. **FTS5 freshness (D-11 / SE-01):** After the merge transaction commits,
 *    the replayer calls SearchIndexWriterContract::upsertForTransaction() for
 *    every SET/CreateRow-touched transactions row, and deleteForTransaction()
 *    for every tombstoned transactions row. These calls happen OUTSIDE the
 *    merge transaction (Pitfall 3 — FTS5 cannot participate in a SQLite
 *    transaction that also writes to base tables). A FTS hiccup never breaks
 *    merge determinism: each call is wrapped in try/catch → quarantine.
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
    /**
     * Device id for the pair-link cascade compensating op (D-08 / CR-03).
     *
     * Cascade ops are deterministically re-derived by the replayer on every
     * replay (incremental AND rebuild), never network-received, so they carry
     * no Ed25519 key and no signature. The signature gate allow-lists this
     * device id via isSystemDevice().
     */
    public const string SYSTEM_CASCADE_DEVICE_ID = 'system-cascade';

    /**
     * Device id used to label FTS-freshness quarantine records.
     */
    private const string SYSTEM_FTS_DEVICE_ID = 'system-fts';

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
     * @param  SearchIndexWriterContract|null  $searchWriter  FTS5 freshness hook (D-11 / SE-01).
     *                                                        Null disables FTS updates (used in OpLogRebuilder rebuild path where search is refreshed
     *                                                        in bulk after rebuild, and in tests that do not need FTS).
     */
    public function __construct(
        private DatabaseManager $db,
        private array $deviceKeys = [],
        ?MergeRulesRegistry $rules = null,
        ?Clock $wallClock = null,
        private readonly ?SearchIndexWriterContract $searchWriter = null,
    ) {
        $this->signer = new DeviceKeySigner;
        $this->rules = $rules ?? new MergeRulesRegistry;
        $this->strategies = [
            'lww' => new LwwPerFieldStrategy,
            'g_counter' => new GCounterStrategy,
            'or_set' => new OrSetStrategy,
        ];
        // Wall clock: injected (for tests/DI) or resolved from the container so
        // it stays consistent with the rest of the codebase (IN-03). The
        // container's Clock and the local Carbon fallback both honour
        // CarbonImmutable::setTestNow(), so replay timestamps remain deterministic
        // under tests either way. The container resolution is guarded because the
        // replayer is also constructed outside a booted app (e.g. some unit tests).
        $this->clock = $wallClock ?? $this->resolveClock();
    }

    /**
     * Resolve a Clock from the container, falling back to a Carbon-backed Clock
     * when no container binding is available (IN-03).
     */
    private function resolveClock(): Clock
    {
        $container = Container::getInstance();

        if ($container->bound(Clock::class)) {
            return $container->make(Clock::class);
        }

        return new class implements Clock
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
     * Encode a strategy-resolved value into a form a SQLite column can bind.
     *
     * CR-02: LWW resolves to a scalar (or null), G-Counter to an int — both bind
     * directly. OR-Set resolves to a `list<array{v,tag}>` (a PHP array), which a
     * query-builder bind cannot accept ("Array to string conversion"). Any
     * non-scalar, non-null result is JSON-encoded so the set survives the round
     * trip through the column. Scalars and null pass through unchanged so the
     * existing LWW/G-Counter behaviour is byte-for-byte identical.
     *
     * @throws \JsonException If a non-scalar value cannot be JSON-encoded; the
     *                        caller catches this and routes the op to quarantine.
     */
    private function encodeColumnValue(mixed $resolved): mixed
    {
        if ($resolved === null || is_scalar($resolved)) {
            return $resolved;
        }

        return json_encode($resolved, JSON_THROW_ON_ERROR);
    }

    /**
     * Whether a device id belongs to a deterministically re-derived system op
     * (e.g. the pair-link cascade) that legitimately bypasses the Ed25519
     * signature gate (CR-03). These ops are produced ONLY by the replayer
     * itself and are reproduced identically on rebuild, so they are trusted by
     * construction. The I1 cross-user gate still applies to them.
     */
    private function isSystemDevice(string $deviceId): bool
    {
        return $deviceId === self::SYSTEM_CASCADE_DEVICE_ID;
    }

    /**
     * Replay a merged set of op-log entries against the real SQLite schema.
     *
     * 1. Filter + verify signatures (I1 + Ed25519 gate). Rejected → quarantine
     *    ONLY (never the authoritative op-log). VERIFIED entries are persisted to
     *    op_log_entries (upsert-by-identity) — so the durable log holds only
     *    authenticated, scoped rows (WR-08).
     * 2. Sort by HLC total order [l, c, device_id].
     * 3. Single-pass resolve per (table, pk, field) via strategy dispatch.
     * 4. Apply in a DB transaction (WHERE user_id = $userId on all writes — I2).
     *
     * @param  list<OpLogEntry>  $entries  Entries from all devices (any order).
     * @param  int  $userId  Scope all DB writes to this user.
     */
    public function replay(array $entries, int $userId): void
    {
        $now = $this->clock->now()->toDateTimeString();

        // Step 1: filter to the scoped userId (I1 defense in depth) + Ed25519 gate.
        // WR-08: a rejected (forged / cross-user) entry is routed to quarantine
        // ONLY — it is NEVER written to the authoritative op_log_entries table, so
        // attacker-controlled rows can never become durable, replayable, or part of
        // a log export. Only entries that pass the gate are persisted below.
        $verified = [];

        foreach ($entries as $entry) {
            if ($entry->userId !== $userId) {
                $this->quarantine($entry, 'cross_user', $now);

                continue;
            }

            // Table allow-list gate: only tables registered in
            // MergeRulesRegistry may be written via op-log replay. Without
            // this gate, a compromised-but-signature-valid peer device could
            // send a SET/DELETE/CreateRow op against ANY wire-supplied table
            // name (e.g. 'device_registry', field 'ed25519_public_key_hex')
            // and strategyFor()'s unknown-table default of 'lww' would let it
            // apply — a full trust-store takeover. This MUST run before the
            // Ed25519 signature gate and persistVerifiedEntry() so an
            // unregistered-table op is never authenticated into the durable
            // log, and before any strategy resolution or DB write.
            if (! $this->rules->isRegistered($entry->table)) {
                $this->quarantine($entry, 'unknown_table', $now);

                continue;
            }

            // CR-03: system cascade ops are deterministically re-derived by the
            // replayer itself (never network-received), so they carry no device
            // key and no signature. Allow-list them past the Ed25519 gate
            // deterministically — both during incremental replay and during a
            // full rebuild (which replays the persisted cascade op). The I1
            // cross-user gate above STILL applies, and these ops only ever set
            // a transaction's `type` for the scoped user, so they cannot be a
            // privilege-escalation vector.
            if ($this->isSystemDevice($entry->deviceId)) {
                $this->persistVerifiedEntry($entry, $now);
                $verified[] = $entry;

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

            // Verified — persist to the authoritative durable log (WR-08).
            $this->persistVerifiedEntry($entry, $now);
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
        // The tombstone HLC is captured so the compensating op can be given a real,
        // monotonic HLC that sorts deterministically AFTER the tombstone (CR-03).
        /** @var list<array{partnerId: int, deletedType: string, tombHlcL: int, tombHlcC: int}> $pairCascades */
        $pairCascades = [];

        // FTS5 freshness tracking (D-11 / SE-01): collected inside transaction,
        // consumed OUTSIDE the transaction (Pitfall 3 — FTS5 calls must be post-commit).
        /** @var list<int> $touchedTransactionIds */
        $touchedTransactionIds = [];

        /** @var list<int> $tombstonedTransactionIds */
        $tombstonedTransactionIds = [];

        $this->db->connection()->transaction(
            function () use (
                $candidatesByField,
                $tombstones,
                $creates,
                $userId,
                $now,
                &$pairCascades,
                &$touchedTransactionIds,
                &$tombstonedTransactionIds,
            ): void {
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
                                // CR-02: a non-scalar strategy result (OrSet → list<array>)
                                // must be JSON-encoded before it reaches a SQLite column.
                                // Encoding inside the try means an encode failure also
                                // routes to quarantine instead of aborting the whole batch.
                                $payload[$field] = $this->encodeColumnValue($strategy->resolve($fieldEntries));
                            } catch (\Throwable) {
                                $this->quarantine($fieldEntries[0], 'strategy_error', $now);

                                continue 2;  // skip this entire row
                            }
                        }

                        // SEC finding: a CreateRow op may legitimately carry a
                        // 'user_id' field (envelope_assignments/_settings
                        // _create_required lists include it), but the
                        // field-assembly loop above lets an op-supplied
                        // 'user_id' overwrite the seeded, authoritative
                        // $payload['user_id'] = $userId. A malicious device
                        // of user A can craft an entry with
                        // entry->userId = A (passing the I1 cross_user gate)
                        // but dirtyFields['user_id'] = B, and insertOrIgnore
                        // would then insert a row into user B's data — I2's
                        // WHERE user_id = $userId guard does not protect
                        // INSERTs (they have no WHERE clause). Reject the
                        // whole row when the supplied value disagrees with
                        // $userId, using the same 'cross_user' reason as the
                        // I1 gate.
                        if (isset($fields['user_id'])) {
                            $suppliedUserIdValue = $payload['user_id'] ?? null;
                            $suppliedUserId = is_numeric($suppliedUserIdValue) ? (int) $suppliedUserIdValue : null;

                            if ($suppliedUserId !== $userId) {
                                $this->quarantine($fields['user_id'][0], 'cross_user', $now);

                                continue;
                            }
                        }

                        // Defense-in-depth: force the authoritative user_id
                        // even when the op-supplied value matched (or none
                        // was supplied) — never trust a wire-derived value
                        // for this column.
                        $payload['user_id'] = $userId;

                        // insertOrIgnore makes re-applying the same CREATE idempotent.
                        $this->db->connection()
                            ->table($table)
                            ->insertOrIgnore($payload);

                        // Track for FTS freshness (transactions table only).
                        if ($table === 'transactions' && is_int($pk)) {
                            $touchedTransactionIds[] = $pk;
                        }
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
                                                'tombHlcL' => $tomb->hlcL,
                                                'tombHlcC' => $tomb->hlcC,
                                            ];
                                        }
                                    }
                                }

                                $this->db->connection()
                                    ->table($table)
                                    ->where('id', $pk)
                                    ->where('user_id', $userId)
                                    ->delete();

                                // Track tombstoned transactions for FTS delete.
                                if ($table === 'transactions' && is_int($pk)) {
                                    $tombstonedTransactionIds[] = $pk;
                                }

                                continue;
                            }
                        }

                        // Edit wins (or no tombstone) — apply each surviving field via strategy.
                        foreach ($fields as $field => $fieldEntries) {
                            try {
                                $strategyKey = $this->rules->strategyFor($table, $field);
                                $strategy = $this->strategies[$strategyKey] ?? $this->strategies['lww'];
                                // CR-02: encode AND write inside the try. The original
                                // code computed the value in the try but ran ->update()
                                // outside it — so a non-scalar value (OrSet → list<array>)
                                // threw during binding and propagated out of replay(),
                                // rolling back the ENTIRE merge transaction. Now both the
                                // encode and the column write are guarded: a single bad op
                                // is quarantined and the rest of the batch survives.
                                $columnValue = $this->encodeColumnValue($strategy->resolve($fieldEntries));

                                $this->db->connection()
                                    ->table($table)
                                    ->where('id', $pk)
                                    ->where('user_id', $userId)
                                    ->update([$field => $columnValue]);
                            } catch (\Throwable) {
                                $this->quarantine($fieldEntries[0], 'strategy_error', $now);

                                continue;
                            }
                        }

                        // Track touched transactions for FTS upsert.
                        if ($table === 'transactions' && is_int($pk)) {
                            $touchedTransactionIds[] = $pk;
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
                                        'tombHlcL' => $tomb->hlcL,
                                        'tombHlcC' => $tomb->hlcC,
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

                        // Track tombstoned transactions for FTS delete.
                        if ($table === 'transactions' && is_int($pk)) {
                            $tombstonedTransactionIds[] = $pk;
                        }
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

            // CR-03: persist the compensating op with a REAL, monotonic HLC that
            // sorts deterministically AFTER the tombstone (tombstone HLC, counter+1,
            // tie-broken by the SYSTEM_CASCADE_DEVICE_ID). The old code used HLC
            // [0,0] + empty signature, which (a) sorted the cascade op FIRST so a
            // rebuild re-ran the tombstone AFTER it and reverted the reclassification,
            // and (b) was quarantined as missing_device_key/forged_signature on every
            // re-replay. The signature gate now deterministically allow-lists
            // SYSTEM_CASCADE_DEVICE_ID (see isSystemDevice()), and the rebuilder
            // replays this persisted op so the cascade is reproduced byte-for-byte.
            //
            // upsert-by-identity keeps re-replay idempotent (the op is deterministic).
            $this->db->connection()->table('op_log_entries')->updateOrInsert(
                [
                    'user_id' => $userId,
                    'device_id' => self::SYSTEM_CASCADE_DEVICE_ID,
                    'table_name' => 'transactions',
                    'pk' => (string) $partnerId,
                    'field' => 'type',
                    'hlc_l' => $cascade['tombHlcL'],
                    'hlc_c' => $cascade['tombHlcC'] + 1,
                ],
                [
                    'op_type' => OpType::Set->value,
                    'value' => json_encode($newType, JSON_THROW_ON_ERROR),
                    'signature' => '',
                    'recorded_at' => $now,
                ],
            );

            // Reclassified partner is a touched transaction — FTS doc must be rebuilt.
            $touchedTransactionIds[] = $partnerId;
        }

        // Step 6: FTS5 freshness (D-11 / SE-01).
        // MUST be OUTSIDE the merge transaction (Pitfall 3: FTS5 shadow-table writes
        // cannot participate in a transaction that also touches the base table).
        // A FTS hiccup never breaks merge determinism — each call is try/catch guarded.
        if ($this->searchWriter !== null) {
            foreach ($touchedTransactionIds as $txId) {
                try {
                    $this->searchWriter->upsertForTransaction($txId, $userId);
                } catch (\Throwable) {
                    $this->quarantineSearchError($txId, 'upsert', $userId, $now);
                }
            }

            foreach ($tombstonedTransactionIds as $txId) {
                try {
                    $this->searchWriter->deleteForTransaction($txId, $userId);
                } catch (\Throwable) {
                    $this->quarantineSearchError($txId, 'delete', $userId, $now);
                }
            }
        }
    }

    /**
     * Persist a VERIFIED entry to the authoritative op_log_entries table
     * (upsert-by-identity, so replay is idempotent).
     *
     * WR-08: this is called ONLY after an entry passes the I1 + Ed25519 gate
     * (or is an allow-listed system op), so the durable log never holds
     * forged or cross-user rows.
     */
    private function persistVerifiedEntry(OpLogEntry $entry, string $now): void
    {
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

    /**
     * Write a structured quarantine record for a failed FTS5 freshness call.
     * Never throws. An FTS hiccup must never break merge determinism.
     *
     * @param  string  $operation  'upsert'|'delete'
     */
    private function quarantineSearchError(int $transactionId, string $operation, int $userId, string $now): void
    {
        try {
            $this->db->connection()->table('op_log_quarantine')->insert([
                'user_id' => $userId,
                'table_name' => 'transactions',
                'pk' => (string) $transactionId,
                'device_id' => self::SYSTEM_FTS_DEVICE_ID,
                'reason' => 'strategy_error',
                'hlc_l' => 0,
                'hlc_c' => 0,
                'raw_value' => json_encode(['fts_operation' => $operation], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        } catch (\Throwable) {
            // Quarantine write failure must not propagate.
        }
    }
}
