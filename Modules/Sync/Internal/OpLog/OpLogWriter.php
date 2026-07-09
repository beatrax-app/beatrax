<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use RuntimeException;

/**
 * Persists op-log entries to the op_log_entries table.
 *
 * Responsibilities:
 *   - Always-JSON encode the raw PHP value (SE-07 producer side).
 *   - Sign each entry via DeviceKeySigner (Ed25519, ext-sodium).
 *   - Tick the HLC and persist the updated clock state in the same
 *     DB transaction as the op insert (D-12 monotonic guard).
 *   - Restore the HLC high-water mark from hlc_clock_state on construction
 *     so a restart or wall-clock rewind cannot lower the logical clock (Pitfall 6).
 *
 * ## Always-JSON wire contract (SE-07)
 *
 * PHP null maps to SQL NULL (the clear/tombstone sentinel).
 * All other values are stored as json_encode($rawValue, JSON_THROW_ON_ERROR).
 * NEVER call json_encode(null) — that would store the JSON string "null"
 * where SQL NULL is the sentinel.
 *
 * ## HLC persistence (D-12)
 *
 * After every write, hlc_clock_state is updated (upserted) atomically in the
 * same DB transaction. On construction, any existing hlc_clock_state row for
 * (user_id, device_id) is read and clock->receive() is called to restore the
 * high-water mark.
 *
 * ## GDK encrypt-on-write hook (Phase 14, CRYPT-01, D-02)
 *
 * Before a sensitive field's JSON-encoded value ever reaches op_log_entries
 * (and therefore the wire — this is the "doubles as transport encryption"
 * boundary, 14-RESEARCH.md Pattern 1), it is encrypted under the CURRENT GDK
 * epoch and the entry is tagged with `gdk_epoch`. The epoch id is bound as
 * AEAD associated data (`"{table}:{pk}:{field}:{epochId}"`) so relabeling the
 * tag on a stored ciphertext invalidates the authentication tag. When GDK
 * encryption is not yet enabled for this user (no `sync_encryption_state`
 * row, or the app-lock is currently locked), the write falls back to
 * plaintext with a null `gdk_epoch` — pre-migration rows stay plaintext
 * until the Plan 06 migration converts them.
 */
final class OpLogWriter
{
    public function __construct(
        private readonly HybridLogicalClock $clock,
        private readonly DatabaseManager $db,
        private readonly DeviceKeySigner $signer,
        private readonly Clock $wallClock,
        private readonly string $deviceId,
        private readonly int $userId,
        private readonly string $secretKey,
        private readonly string $publicKey,
        private readonly SensitiveFieldRegistry $sensitiveFields,
        private readonly OpLogFieldCrypto $fieldCrypto,
        private readonly GdkKeyringService $keyring,
        private readonly Session $session,
    ) {
        $this->restoreClockState();
    }

    /**
     * Expose the hex-encoded Ed25519 public key for this device.
     *
     * Callers (e.g., OpLogReplayer factory code) can use this to build the
     * device-key map required for signature verification.
     */
    public function publicKeyHex(): string
    {
        return bin2hex($this->publicKey);
    }

    /**
     * Persist a Set op for a single field change.
     *
     * @param  string  $table  Target table name.
     * @param  int|string  $pk  Primary key of the row being mutated.
     * @param  string  $field  Column name being changed.
     * @param  mixed  $value  The new PHP value. PHP null = explicit SET NULL sentinel.
     */
    public function writeSet(string $table, int|string $pk, string $field, mixed $value): void
    {
        $jsonValue = $value !== null ? json_encode($value, JSON_THROW_ON_ERROR) : null;
        [$jsonValue, $gdkEpochId] = $this->maybeEncrypt($table, $pk, $field, $jsonValue);
        $this->writeEntry($table, $pk, $field, $jsonValue, OpType::Set, $gdkEpochId);
    }

    /**
     * Persist CreateRow ops for all fields of a new row.
     *
     * Emits one op per field, each with an independent HLC tick.
     *
     * @param  string  $table  Target table name.
     * @param  int|string  $pk  Primary key of the new row.
     * @param  array<string, mixed>  $fields  Field => value map of all required columns.
     */
    public function writeCreateRow(string $table, int|string $pk, array $fields): void
    {
        foreach ($fields as $field => $rawValue) {
            $jsonValue = $rawValue !== null ? json_encode($rawValue, JSON_THROW_ON_ERROR) : null;
            [$jsonValue, $gdkEpochId] = $this->maybeEncrypt($table, $pk, $field, $jsonValue);
            $this->writeEntry($table, $pk, $field, $jsonValue, OpType::CreateRow, $gdkEpochId);
        }
    }

    /**
     * Encrypt $jsonValue under the CURRENT GDK epoch when (table, field) is
     * on the D-02b sensitive allow-list. Returns [value, gdkEpochId] — the
     * (possibly ciphertext) value to persist, and the epoch id to tag the
     * entry with (null when the value was left plaintext).
     *
     * Falls back to plaintext + null epoch when GDK encryption is not
     * currently usable for this user (never enabled yet, or the app-lock is
     * locked) — never blocks the write, matching every other GDK read site's
     * "false not garbage" / pass-through posture.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function maybeEncrypt(string $table, int|string $pk, string $field, ?string $jsonValue): array
    {
        if ($jsonValue === null || ! $this->sensitiveFields->isSensitive($table, $field)) {
            return [$jsonValue, null];
        }

        $epoch = $this->tryCurrentEpoch();
        if ($epoch === null) {
            return [$jsonValue, null];
        }

        $rawKey = sodium_hex2bin($epoch->keyHex);
        try {
            $ciphertext = $this->fieldCrypto->encrypt(
                $jsonValue,
                $rawKey,
                "{$table}:{$pk}:{$field}:{$epoch->epochId}",
            );
        } finally {
            sodium_memzero($rawKey);
        }

        return [$ciphertext, $epoch->epochId];
    }

    /**
     * Resolve the current GDK epoch, or null when encryption is not
     * currently usable (never enabled for this user, or the app-lock KEK is
     * unavailable) — never throws.
     */
    private function tryCurrentEpoch(): ?GdkEpoch
    {
        try {
            return $this->keyring->currentEpoch($this->userId, $this->session);
        } catch (LogicException|RuntimeException) {
            return null;
        }
    }

    /**
     * Persist a DeleteTombstone op.
     *
     * @param  string  $table  Target table name.
     * @param  int|string  $pk  Primary key of the row being deleted.
     */
    public function writeDelete(string $table, int|string $pk): void
    {
        $this->writeEntry($table, $pk, '__tombstone__', null, OpType::DeleteTombstone);
    }

    /**
     * Restore the HLC high-water mark from the persisted clock state.
     *
     * Called once in __construct. Prevents clock rewind on restart (Pitfall 6):
     * the next tick() starts from max(wall_ms, last_l), never below last_l.
     */
    private function restoreClockState(): void
    {
        $state = $this->db->connection()
            ->table('hlc_clock_state')
            ->where('user_id', $this->userId)
            ->where('device_id', $this->deviceId)
            ->first();

        if ($state !== null) {
            $lastL = is_numeric($state->last_l) ? (int) $state->last_l : 0;
            $lastC = is_numeric($state->last_c) ? (int) $state->last_c : 0;
            $this->clock->receive($lastL, $lastC);
        }
    }

    /**
     * Build, sign, and persist a single op-log entry in a DB transaction.
     * The hlc_clock_state row is upserted atomically in the same transaction.
     */
    private function writeEntry(
        string $table,
        int|string $pk,
        string $field,
        ?string $jsonValue,
        OpType $opType,
        ?int $gdkEpoch = null,
    ): void {
        [$hlcL, $hlcC] = $this->clock->tick();

        // Build a stub entry to get the signing payload (signature is empty at this stage).
        $stub = new OpLogEntry(
            table: $table,
            pk: $pk,
            field: $field,
            value: $jsonValue,
            hlcL: $hlcL,
            hlcC: $hlcC,
            deviceId: $this->deviceId,
            opType: $opType,
            signature: '',
            userId: $this->userId,
            gdkEpoch: $gdkEpoch,
        );

        $signature = $this->signer->sign($stub->signingPayload(), $this->secretKey);

        // Reconstruct with the real signature (OpLogEntry is readonly).
        $entry = new OpLogEntry(
            table: $table,
            pk: $pk,
            field: $field,
            value: $jsonValue,
            hlcL: $hlcL,
            hlcC: $hlcC,
            deviceId: $this->deviceId,
            opType: $opType,
            signature: $signature,
            userId: $this->userId,
            gdkEpoch: $gdkEpoch,
        );

        $now = $this->wallClock->now()->toDateTimeString();

        $this->db->connection()->transaction(function () use ($entry, $hlcL, $hlcC, $now): void {
            $this->db->connection()->table('op_log_entries')->insert([
                'user_id' => $entry->userId,
                'device_id' => $entry->deviceId,
                'table_name' => $entry->table,
                'pk' => (string) $entry->pk,
                'field' => $entry->field,
                'op_type' => $entry->opType->value,
                'value' => $entry->value,
                'gdk_epoch' => $entry->gdkEpoch,
                'hlc_l' => $entry->hlcL,
                'hlc_c' => $entry->hlcC,
                'signature' => $entry->signature,
                'recorded_at' => $now,
            ]);

            // Persist the updated clock state atomically (D-12).
            // Key the upsert on the composite PRIMARY KEY (user_id, device_id)
            // ONLY — the old 'id' => 1 singleton key (CR-01) is gone, so a
            // second device/user gets its own row instead of colliding on id=1.
            $this->db->connection()->table('hlc_clock_state')->updateOrInsert(
                [
                    'user_id' => $this->userId,
                    'device_id' => $this->deviceId,
                ],
                [
                    'last_l' => $hlcL,
                    'last_c' => $hlcC,
                    'updated_at' => $now,
                ],
            );
        });
    }
}
