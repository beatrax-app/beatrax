<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use LogicException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use RuntimeException;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class OpLogWriter
{
    // Always-JSON wire contract: PHP null maps to SQL NULL (the
    // clear/tombstone sentinel); all other values are
    // json_encode($rawValue, JSON_THROW_ON_ERROR) — NEVER json_encode(null),
    // which would store the JSON string "null" where SQL NULL is the sentinel.
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
        private readonly SessionFactory $session,
    ) {
        $this->restoreClockState();
    }

    // Callers (e.g. OpLogReplayer factory code) use this to build the
    // device-key map required for signature verification.
    public function publicKeyHex(): string
    {
        return bin2hex($this->publicKey);
    }

    /**
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
     * @param  string  $table  Target table name.
     * @param  int|string  $pk  Primary key of the new row.
     * @param  array<string, mixed>  $fields  Field => value map of all required columns (emits one op per field, each with an independent HLC tick).
     */
    public function writeCreateRow(string $table, int|string $pk, array $fields): void
    {
        foreach ($fields as $field => $rawValue) {
            $jsonValue = $rawValue !== null ? json_encode($rawValue, JSON_THROW_ON_ERROR) : null;
            [$jsonValue, $gdkEpochId] = $this->maybeEncrypt($table, $pk, $field, $jsonValue);
            $this->writeEntry($table, $pk, $field, $jsonValue, OpType::CreateRow, $gdkEpochId);
        }
    }

    // Encrypts $jsonValue under the CURRENT GDK epoch when (table, field) is
    // on the sensitive allow-list. Falls back to plaintext + null epoch when
    // GDK encryption is not currently usable for this user — never blocks
    // the write.
    /**
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

    // Null when encryption is not currently usable for this user (never
    // enabled, or the app-lock KEK is unavailable) — never throws.
    private function tryCurrentEpoch(): ?GdkEpoch
    {
        try {
            return $this->keyring->currentEpoch($this->userId, ($this->session)());
        } catch (LogicException|RuntimeException) {
            return null;
        }
    }

    public function writeDelete(string $table, int|string $pk): void
    {
        $this->writeEntry($table, $pk, '__tombstone__', null, OpType::DeleteTombstone);
    }

    // Called once in __construct. Prevents clock rewind on restart: the next
    // tick() starts from max(wall_ms, last_l), never below last_l.
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

    private function writeEntry(
        string $table,
        int|string $pk,
        string $field,
        ?string $jsonValue,
        OpType $opType,
        ?int $gdkEpoch = null,
    ): void {
        [$hlcL, $hlcC] = $this->clock->tick();

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

            // Key the upsert on the composite PRIMARY KEY (user_id,
            // device_id) so a second device/user gets its own row instead of
            // colliding on a shared singleton key.
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
