<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\GdkKeyring;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\Merge\Strategies\GCounterStrategy;
use Modules\Sync\Internal\Merge\Strategies\LwwPerFieldStrategy;
use Modules\Sync\Internal\Merge\Strategies\MergeStrategyInterface;
use Modules\Sync\Internal\Merge\Strategies\OrSetStrategy;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class OpLogReplayer
{
    // Cascade ops are deterministically re-derived by the replayer on every
    // replay (incremental AND rebuild), never network-received, so they
    // carry no Ed25519 key and no signature. The signature gate allow-lists
    // this device id via isSystemDevice().
    public const string SYSTEM_CASCADE_DEVICE_ID = 'system-cascade';

    private const string SYSTEM_FTS_DEVICE_ID = 'system-fts';

    private DeviceKeySigner $signer;

    private MergeRulesRegistry $rules;

    /** @var array<string, MergeStrategyInterface> */
    private array $strategies;

    private Clock $clock;

    private SensitiveFieldRegistry $sensitiveFields;

    private ?OpLogFieldCrypto $fieldCrypto;

    private ?GdkKeyringService $keyringService;

    private ?SensitiveColumnCodec $columnCodec;

    private ?Session $session;

    /**
     * @param  DatabaseManager  $db  Raw DB access (bypasses Eloquent model events).
     * @param  array<string, string>  $deviceKeys  device-id => hex Ed25519 public key.
     * @param  MergeRulesRegistry|null  $rules  Config-driven strategy registry (default: new instance).
     * @param  Clock|null  $wallClock  Clock for recorded_at timestamps (default: resolved from container).
     * @param  SearchIndexWriterContract|null  $searchWriter  FTS5 freshness hook.
     *                                                        Null disables FTS updates (used in OpLogRebuilder rebuild path where search is refreshed
     *                                                        in bulk after rebuild, and in tests that do not need FTS).
     * @param  SensitiveFieldRegistry|null  $sensitiveFields  Encryption-scope allow-list (default: new instance — stateless config).
     * @param  OpLogFieldCrypto|null  $fieldCrypto  GDK entry-value AEAD (default: resolved from container; null disables
     *                                              decrypt — a GDK-tagged entry then fails closed to quarantine).
     * @param  GdkKeyringService|null  $keyringService  Per-user epoch keyring (default: resolved from container).
     * @param  SensitiveColumnCodec|null  $columnCodec  Rotation-safe projection-column re-encrypt (default: resolved
     *                                                  from container; null leaves the projection column plaintext).
     * @param  Session|null  $session  Needed to release the app-lock KEK for keyring reads (default: resolved from container).
     */
    public function __construct(
        private DatabaseManager $db,
        private array $deviceKeys = [],
        ?MergeRulesRegistry $rules = null,
        ?Clock $wallClock = null,
        private readonly ?SearchIndexWriterContract $searchWriter = null,
        ?SensitiveFieldRegistry $sensitiveFields = null,
        ?OpLogFieldCrypto $fieldCrypto = null,
        ?GdkKeyringService $keyringService = null,
        ?SensitiveColumnCodec $columnCodec = null,
        ?Session $session = null,
    ) {
        $this->signer = new DeviceKeySigner;
        $this->rules = $rules ?? new MergeRulesRegistry;
        $this->strategies = [
            'lww' => new LwwPerFieldStrategy,
            'g_counter' => new GCounterStrategy,
            'or_set' => new OrSetStrategy,
        ];
        // Wall clock: injected (for tests/DI) or resolved from the container.
        // Both honour CarbonImmutable::setTestNow(), so replay timestamps
        // stay deterministic under tests; container resolution is guarded
        // since the replayer is also constructed outside a booted app.
        $this->clock = $wallClock ?? $this->resolveClock();
        $this->sensitiveFields = $sensitiveFields ?? new SensitiveFieldRegistry;
        $this->fieldCrypto = $fieldCrypto ?? $this->resolveFromContainer(OpLogFieldCrypto::class);
        $this->keyringService = $keyringService ?? $this->resolveFromContainer(GdkKeyringService::class);
        $this->columnCodec = $columnCodec ?? $this->resolveFromContainer(SensitiveColumnCodec::class);
        $this->session = $session ?? $this->resolveFromContainer(Session::class);
    }

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
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private function resolveFromContainer(string $class): ?object
    {
        $container = Container::getInstance();

        if (! $container->bound($class)) {
            return null;
        }

        $instance = $container->make($class);

        return $instance instanceof $class ? $instance : null;
    }

    // Unconditional json_decode — no raw-string fallback. PHP null input
    // means tombstone/clear sentinel -> return null (write SQL NULL); all
    // other inputs are JSON-encoded by the producer (e.g. '"null"' decodes
    // to the PHP string "null", not PHP null). Public so AlwaysJsonWireContractTest can call it directly.
    public function decodeValue(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    // LWW/G-Counter bind directly; OR-Set resolves to a list<array{v,tag}>,
    // which a query-builder bind cannot accept ("Array to string
    // conversion"), so any non-scalar, non-null result is JSON-encoded.
    /**
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

    // Whether a device id belongs to a deterministically re-derived system
    // op (e.g. the pair-link cascade) that legitimately bypasses the
    // Ed25519 signature gate. Produced ONLY by the replayer itself and
    // reproduced identically on rebuild, so trusted by construction.
    private function isSystemDevice(string $deviceId): bool
    {
        return $deviceId === self::SYSTEM_CASCADE_DEVICE_ID;
    }

    // Loads $userId's GDK keyring, or null when it cannot currently be
    // loaded (crypto services unavailable, no session, app locked, or GDK
    // never enabled). Never throws — callers treat null as "cannot decrypt right now".
    private function tryLoadKeyring(int $userId): ?GdkKeyring
    {
        if ($this->keyringService === null || $this->session === null) {
            return null;
        }

        try {
            $keyring = $this->keyringService->loadKeyring($userId, $this->session);
        } catch (\LogicException) {
            return null;
        }

        return $keyring->epochs() === [] ? null : $keyring;
    }

    // Decrypts a GDK-tagged sensitive entry's value for strategy resolution.
    // Returns a NEW OpLogEntry carrying the decrypted plaintext (->gdkEpoch
    // unchanged, needed so a later write-back knows the source was
    // encrypted). Returns null (quarantined) on any decrypt failure — NEVER throws.
    private function decryptForStrategy(OpLogEntry $entry, ?GdkKeyring $keyring, string $now): ?OpLogEntry
    {
        if ($entry->value === null || $entry->gdkEpoch === null) {
            return $entry;
        }

        if ($this->fieldCrypto === null || $keyring === null) {
            $this->quarantine($entry, 'gdk_decrypt_failed', $now);

            return null;
        }

        $keyHex = $keyring->keyFor($entry->gdkEpoch);
        if ($keyHex === null) {
            $this->quarantine($entry, 'gdk_decrypt_failed', $now);

            return null;
        }

        $rawKey = sodium_hex2bin($keyHex);
        try {
            $plain = $this->fieldCrypto->decrypt(
                $entry->value,
                $rawKey,
                "{$entry->table}:{$entry->pk}:{$entry->field}:{$entry->gdkEpoch}",
            );
        } finally {
            sodium_memzero($rawKey);
        }

        if ($plain === false) {
            $this->quarantine($entry, 'gdk_decrypt_failed', $now);

            return null;
        }

        return new OpLogEntry(
            table: $entry->table,
            pk: $entry->pk,
            field: $entry->field,
            value: $plain,
            hlcL: $entry->hlcL,
            hlcC: $entry->hlcC,
            deviceId: $entry->deviceId,
            opType: $entry->opType,
            signature: $entry->signature,
            userId: $entry->userId,
            gdkEpoch: $entry->gdkEpoch,
        );
    }

    // Re-encrypts a plaintext sensitive-field value for the PROJECTION
    // column write-back (rotation-safe, current-epoch AD) so it stays
    // ciphertext at rest. Pass-through when the field isn't sensitive, the
    // value isn't a string, or the codec/encryption isn't usable for $userId.
    private function reencryptForProjection(string $table, string $field, mixed $value, int $userId): mixed
    {
        if ($this->columnCodec === null || $this->session === null || ! is_string($value)) {
            return $value;
        }

        if (! $this->sensitiveFields->isSensitive($table, $field)) {
            return $value;
        }

        return $this->columnCodec->encryptValue($table, $field, $value, $userId, $this->session);
    }

    // Replays a merged set of op-log entries against the real SQLite schema:
    // filter + verify signatures (rejected -> quarantine only), sort by HLC
    // total order, single-pass resolve per (table, pk, field) via strategy
    // dispatch, then apply in a DB transaction scoped to $userId (see @link).
    /**
     * @param  list<OpLogEntry>  $entries  Entries from all devices (any order).
     * @param  int  $userId  Scope all DB writes to this user.
     */
    public function replay(array $entries, int $userId): void
    {
        $now = $this->clock->now()->toDateTimeString();

        // Step 1: filter to the scoped userId (defense in depth) + Ed25519
        // gate. A rejected entry is routed to quarantine ONLY — it is NEVER
        // written to the authoritative op_log_entries table.
        $verified = [];

        // GDK keyring: loaded at most once per replay() call, lazily (only
        // if a GDK-tagged sensitive entry is actually encountered), and
        // cached here — never as instance state (this class is readonly and
        // potentially reused across many replay() calls in a headless daemon).
        $keyring = null;
        $keyringLoaded = false;

        foreach ($entries as $entry) {
            if ($entry->userId !== $userId) {
                $this->quarantine($entry, 'cross_user', $now);

                continue;
            }

            // Table allow-list gate: only tables registered in
            // MergeRulesRegistry may be written via op-log replay — without
            // it, a compromised-but-signature-valid peer could target ANY
            // wire-supplied table name, a full trust-store takeover.
            if (! $this->rules->isRegistered($entry->table)) {
                $this->quarantine($entry, 'unknown_table', $now);

                continue;
            }

            // System cascade ops are deterministically re-derived by the
            // replayer itself, so they carry no device key/signature —
            // allow-listed past the Ed25519 gate. The cross-user gate above
            // STILL applies, and they only ever set a transaction's `type`.
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

            // Verified — persistVerifiedEntry() ALWAYS writes $entry->value
            // UNCHANGED (ciphertext, if sensitive); the decrypt step below
            // only affects the in-memory copy used for strategy resolution.
            $this->persistVerifiedEntry($entry, $now);

            if ($entry->gdkEpoch === null || ! $this->sensitiveFields->isSensitive($entry->table, $entry->field)) {
                $verified[] = $entry;

                continue;
            }

            if (! $keyringLoaded) {
                $keyring = $this->tryLoadKeyring($userId);
                $keyringLoaded = true;
            }

            $decrypted = $this->decryptForStrategy($entry, $keyring, $now);

            if ($decrypted === null) {
                // Quarantined inside decryptForStrategy(). The entry is
                // already durably persisted (ciphertext) above — it is
                // simply excluded from strategy resolution.
                continue;
            }

            $verified[] = $decrypted;
        }

        $sorted = $verified;
        usort(
            $sorted,
            fn (OpLogEntry $a, OpLogEntry $b): int => HybridLogicalClock::compare(
                $a->hlcL, $a->hlcC, $a->deviceId,
                $b->hlcL, $b->hlcC, $b->deviceId,
            ),
        );

        // Step 3: single pass — build resolved-value map, tombstone map,
        // creates map. Keyed as candidatesByField[table][pk][field] =>
        // HLC-sorted list<OpLogEntry>; tombstones[table][pk] => winning
        // DELETE_TOMBSTONE entry; creates[table][pk][field] => list<OpLogEntry>.

        /** @var array<string, array<int|string, array<string, list<OpLogEntry>>>> $candidatesByField */
        $candidatesByField = [];

        /** @var array<string, array<int|string, OpLogEntry>> $tombstones */
        $tombstones = [];

        /** @var array<string, array<int|string, array<string, list<OpLogEntry>>>> $creates */
        $creates = [];

        foreach ($sorted as $entry) {
            $pk = $entry->pk;

            if ($entry->opType === OpType::DeleteTombstone) {
                $tombstones[$entry->table][$pk] = $entry;
            } elseif ($entry->opType === OpType::CreateRow) {
                $creates[$entry->table][$pk][$entry->field][] = $entry;
            } else {
                $candidatesByField[$entry->table][$pk][$entry->field][] = $entry;
            }
        }

        // Step 4: apply within a single DB transaction. Pair-link cascade
        // deletions are collected here and applied AFTER commit, with the
        // tombstone HLC captured so the compensating op sorts deterministically after it.
        /** @var list<array{partnerId: int, deletedType: string, tombHlcL: int, tombHlcC: int}> $pairCascades */
        $pairCascades = [];

        // FTS5 freshness tracking: collected inside the transaction,
        // consumed OUTSIDE it (FTS5 calls must be post-commit).
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
                foreach ($creates as $table => $rows) {
                    foreach ($rows as $pk => $fields) {
                        $tomb = $tombstones[$table][$pk] ?? null;

                        if ($tomb !== null) {
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
                                continue;
                            }
                        }

                        $requiredCols = $this->rules->requiredCreateColumns($table);
                        $presentCols = array_keys($fields);
                        $missing = array_diff($requiredCols, $presentCols);

                        if ($missing !== []) {
                            $firstField = reset($fields);
                            if ($firstField !== false && $firstField !== []) {
                                $synth = $firstField[0];
                                $this->quarantine($synth, 'incomplete_create_row', $now);
                            }

                            continue;
                        }

                        $payload = ['user_id' => $userId];

                        foreach ($fields as $field => $fieldEntries) {
                            try {
                                $strategyKey = $this->rules->strategyFor($table, $field);
                                $strategy = $this->strategies[$strategyKey] ?? $this->strategies['lww'];
                                // A non-scalar strategy result (OrSet -> list<array>) must be
                                // JSON-encoded before it reaches a SQLite column; the sensitive
                                // PROJECTION column is then re-encrypted under the CURRENT
                                // epoch (rotation-safe) — the strategy only saw plaintext.
                                $resolvedValue = $this->encodeColumnValue($strategy->resolve($fieldEntries));
                                $payload[$field] = $this->reencryptForProjection($table, $field, $resolvedValue, $userId);
                            } catch (\Throwable) {
                                $this->quarantine($fieldEntries[0], 'strategy_error', $now);

                                continue 2;
                            }
                        }

                        // A CreateRow op may legitimately carry a 'user_id' field, but the
                        // loop above lets it overwrite the seeded, authoritative
                        // $payload['user_id'] — insertOrIgnore has no WHERE clause to stop a
                        // malicious device of user A supplying dirtyFields['user_id'] = B.
                        if (isset($fields['user_id'])) {
                            $suppliedUserIdValue = $payload['user_id'] ?? null;
                            $suppliedUserId = is_numeric($suppliedUserIdValue) ? (int) $suppliedUserIdValue : null;

                            if ($suppliedUserId !== $userId) {
                                $this->quarantine($fields['user_id'][0], 'cross_user', $now);

                                continue;
                            }
                        }

                        // Defense-in-depth: force the authoritative user_id even when the
                        // op-supplied value matched — never trust a wire-derived value here.
                        $payload['user_id'] = $userId;

                        $this->db->connection()
                            ->table($table)
                            ->insertOrIgnore($payload);

                        if ($table === 'transactions' && is_int($pk)) {
                            $touchedTransactionIds[] = $pk;
                        }
                    }
                }

                foreach ($candidatesByField as $table => $rows) {
                    foreach ($rows as $pk => $fields) {
                        $tomb = $tombstones[$table][$pk] ?? null;

                        if ($tomb !== null) {
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

                            // Delete-wins: tombstone HLC >= max field HLC (incl. equal
                            // tie). Before deleting, check for a pair-link cascade — ON
                            // DELETE SET NULL on pair_transaction_id means the transfer
                            // partner survives with pair_transaction_id=NULL.
                            if ($maxFieldEntry !== null && HybridLogicalClock::compare(
                                $tomb->hlcL, $tomb->hlcC, $tomb->deviceId,
                                $maxFieldEntry->hlcL, $maxFieldEntry->hlcC, $maxFieldEntry->deviceId,
                            ) >= 0) {
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

                                if ($table === 'transactions' && is_int($pk)) {
                                    $tombstonedTransactionIds[] = $pk;
                                }

                                continue;
                            }
                        }

                        // Edit wins (or no tombstone). Encode AND write inside the try:
                        // computing the value in the try but ->update() outside it let a
                        // non-scalar (OrSet) throw during binding and roll back the ENTIRE
                        // merge transaction — now a single bad op is quarantined instead.
                        foreach ($fields as $field => $fieldEntries) {
                            try {
                                $strategyKey = $this->rules->strategyFor($table, $field);
                                $strategy = $this->strategies[$strategyKey] ?? $this->strategies['lww'];
                                $columnValue = $this->encodeColumnValue($strategy->resolve($fieldEntries));
                                // The sensitive PROJECTION column is re-encrypted under the
                                // CURRENT epoch (rotation-safe) before the UPDATE.
                                $columnValue = $this->reencryptForProjection($table, $field, $columnValue, $userId);

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

                        if ($table === 'transactions' && is_int($pk)) {
                            $touchedTransactionIds[] = $pk;
                        }
                    }
                }

                // Apply tombstones for (table, pk) pairs that had no SET entries
                // (pairs with a SET are already handled in the loop above).
                foreach ($tombstones as $table => $pks) {
                    foreach ($pks as $pk => $tomb) {
                        if (isset($candidatesByField[$table][$pk])) {
                            continue;
                        }

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

                        if ($table === 'transactions' && is_int($pk)) {
                            $tombstonedTransactionIds[] = $pk;
                        }
                    }
                }
            },
        );

        // Step 5: pair-link cascade reclassification. After the merge
        // transaction, detect orphaned transfer partners and reclassify
        // them. Each compensating op is also persisted to op_log_entries.
        foreach ($pairCascades as $cascade) {
            $partnerId = $cascade['partnerId'];
            $deletedType = $cascade['deletedType'];

            $newType = match ($deletedType) {
                'transfer_out' => 'income',   // partner was transfer_in, now orphaned -> income
                'transfer_in' => 'expense',  // partner was transfer_out, now orphaned -> expense
                default => null,
            };

            if ($newType === null) {
                continue;
            }

            $partnerRow = $this->db->connection()
                ->table('transactions')
                ->where('id', $partnerId)
                ->where('user_id', $userId)
                ->first();

            if ($partnerRow === null || $partnerRow->pair_transaction_id !== null) {
                continue;
            }

            $this->db->connection()
                ->table('transactions')
                ->where('id', $partnerId)
                ->where('user_id', $userId)
                ->update(['type' => $newType]);

            // Persists the compensating op with a REAL, monotonic HLC
            // (tombstone HLC, counter+1) that sorts deterministically AFTER
            // the tombstone — an HLC of [0,0] would sort it FIRST and a
            // rebuild would revert the reclassification.
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

            $touchedTransactionIds[] = $partnerId;
        }

        // Step 6: FTS5 freshness. MUST be OUTSIDE the merge transaction
        // (FTS5 shadow-table writes cannot participate in a transaction
        // that also touches the base table). A FTS hiccup never breaks
        // merge determinism — each call is try/catch guarded.
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

    // Persists a VERIFIED entry to the authoritative op_log_entries table
    // (upsert-by-identity, so replay is idempotent). Called ONLY after an
    // entry passes the cross-user + Ed25519 gate (or is an allow-listed
    // system op), so the durable log never holds forged or cross-user rows.
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
                'gdk_epoch' => $entry->gdkEpoch,
                'signature' => $entry->signature,
                'recorded_at' => $now,
            ],
        );
    }

    /**
     * @param  string  $reason  'cross_user'|'missing_device_key'|'forged_signature'|
     *                          'unknown_table'|'strategy_error'|'incomplete_create_row'|
     *                          'gdk_decrypt_failed'
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
            // Never propagate a quarantine write failure — replay must
            // continue regardless.
        }
    }

    /**
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
            // Never propagate — an FTS-freshness quarantine failure must
            // not break replay.
        }
    }
}
