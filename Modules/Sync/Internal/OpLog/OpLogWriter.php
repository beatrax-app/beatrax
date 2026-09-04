<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use LogicException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use RuntimeException;

final readonly class OpLogWriter implements OpCaptureSink
{
    // The field a tombstone occupies. Named rather than repeated because the
    // deferred queue records the same coordinate for a delete it could not
    // sign, and two spellings of it would be two different coordinates.
    public const string TOMBSTONE_FIELD = '__tombstone__';

    // Always-JSON wire contract: PHP null maps to SQL NULL (the
    // clear/tombstone sentinel); all other values are
    // json_encode($rawValue, JSON_THROW_ON_ERROR) — NEVER json_encode(null),
    // which would store the JSON string "null" where SQL NULL is the sentinel.
    public function __construct(
        private HybridLogicalClock $clock,
        private DatabaseManager $db,
        private DeviceKeySigner $signer,
        private Clock $wallClock,
        private string $deviceId,
        private int $userId,
        private string $secretKey,
        private string $publicKey,
        private SensitiveFieldRegistry $sensitiveFields,
        private OpLogFieldCrypto $fieldCrypto,
        private GdkKeyringService $keyring,
        private SessionFactory $session,
        private MergeRulesRegistry $rules,
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

    // A g_counter field merges as the SUM of each device's own maximum, so an
    // op must carry THIS device's running total. The stored column holds the
    // merged total across every device, and emitting that would re-count the
    // other devices' contributions as ours on the next merge.
    /**
     * @param  int  $delta  How far this device's own count moves. Must be positive.
     *
     * @throws LogicException If $delta would not raise the count.
     */
    public function writeIncrement(string $table, int|string $pk, string $field, int $delta): void
    {
        // GCounterStrategy resolves to the SUM of each device's MAXIMUM, so a
        // total that does not rise is a total no peer will ever adopt: the op
        // goes on the wire, is merged away, and the count silently stops.
        if ($delta < 1) {
            throw new LogicException(
                "OpLogWriter: a g_counter increment must be positive, got {$delta} for {$table}.{$field}."
            );
        }

        $this->writeSet($table, $pk, $field, $this->ownRunningTotal($table, $pk, $field) + $delta);
    }

    // The highest total this device has published for the field. Reading every
    // op it ever wrote to find that maximum made each increment cost the ones
    // before it — 5,000 prior increments took 6.2 ms and 2 MB per call, growing
    // with every call. The newest op IS the maximum: writeIncrement only rises.
    private function ownRunningTotal(string $table, int|string $pk, string $field): int
    {
        $value = $this->db->connection()
            ->table('op_log_entries')
            ->where('user_id', $this->userId)
            ->where('device_id', $this->deviceId)
            ->where('table_name', $table)
            ->where('pk', (string) $pk)
            ->where('field', $field)
            ->orderByDesc('hlc_l')
            ->orderByDesc('hlc_c')
            ->value('value');

        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_int($decoded) ? $decoded : 0;
    }

    /**
     * @param  string  $table  Target table name.
     * @param  int|string  $pk  Primary key of the new row.
     * @param  array<string, mixed>  $fields  Field => value map of all required columns (emits one op per field, each with an independent HLC tick).
     */
    public function writeCreateRow(string $table, int|string $pk, array $fields): void
    {
        foreach ($this->withRowTimestamps($table, $pk, $fields) as $field => $rawValue) {
            $jsonValue = $rawValue !== null ? json_encode($rawValue, JSON_THROW_ON_ERROR) : null;
            [$jsonValue, $gdkEpochId] = $this->maybeEncrypt($table, $pk, $field, $jsonValue);
            $this->writeEntry($table, $pk, $field, $jsonValue, OpType::CreateRow, $gdkEpochId);
        }
    }

    // Thirteen call sites build a create payload: the backfill reads whole rows,
    // the rest name columns by hand, and several named neither timestamp. A row
    // then reached a peer complete or with both null, decided only by whether it
    // predated pairing — and a null created_at is swept by no retention pass.
    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function withRowTimestamps(string $table, int|string $pk, array $fields): array
    {
        if (array_key_exists('created_at', $fields) && array_key_exists('updated_at', $fields)) {
            return $fields;
        }

        // Read back rather than stamped with now(), so the peer records when
        // the row was made and not when it travelled. Never blocks the write,
        // in keeping with maybeEncrypt below.
        try {
            $row = (array) $this->db->connection()->table($table)->where('id', $pk)->first();
        } catch (\Throwable) {
            return $fields;
        }

        // These are columns the caller did not choose to send, so the policy
        // the edit path consults has to be asked here too: users keeps its own
        // timestamps device-local, and a table added tomorrow may as well.
        $offTheWire = $this->rules->columnsNeverOnTheWire($table);

        foreach (['created_at', 'updated_at'] as $column) {
            if (in_array($column, $offTheWire, true)) {
                continue;
            }

            if (! array_key_exists($column, $fields) && array_key_exists($column, $row)) {
                $fields[$column] = $row[$column];
            }
        }

        return $fields;
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
                SensitiveColumnCodec::opLogAssociatedData($table, $pk, $field, $epoch->epochId),
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
        $this->writeEntry($table, $pk, self::TOMBSTONE_FIELD, null, OpType::DeleteTombstone);
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
