<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates the device_registry table — the trusted-device store for this
 * user's paired devices (PAIR-01/PAIR-03, D-03).
 *
 * The table keys on the established (user_id, device_id) composite
 * convention shared by op_log_entries and hlc_clock_state, so a device's
 * registry row, its op-log entries, and its HLC clock state all line up
 * under the same identity. A second user (or a second install of the same
 * user) simply inserts its own row — no shared singleton, no cross-user
 * collision.
 *
 * Column notes:
 *   - `device_id` is a UUID v4 string (matches op_log_entries.device_id).
 *   - `ed25519_public_key_hex` / `x25519_public_key_hex` are 64-hex-char
 *     (32-byte) PUBLIC keys only. No secret-key column exists — secret keys
 *     live exclusively in the encrypted key-file on disk (Plan 02, D-01),
 *     never in the database (STRIDE T-12-02).
 *   - `safety_number_words` is the 6-word BIP39 safety-number, computed
 *     once at pairing time and stored space-separated (D-08).
 *   - `is_self` flags the local device's own row (DeviceKeySigner reads it).
 *   - `confirmed_at` is NULL until the mandatory both-sides safety-number
 *     confirmation completes (D-07). DeviceRegistryService::deviceKeys()
 *     returns ONLY rows where confirmed_at IS NOT NULL — this gates which
 *     keys the OpLogReplayer will trust (STRIDE T-12-03).
 *   - `last_seen_at` is a Phase 13 stub — nullable, never written here.
 *
 * Two indexes:
 *   - `device_registry_user_device_idx` UNIQUE on (user_id, device_id)
 *     — the composite-key uniqueness guard mirroring the op-log model.
 *   - `device_registry_user_idx` on (user_id, confirmed_at)
 *     — supports the confirmed-only deviceKeys() filter and device-list
 *       queries in one indexed scan.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('device_registry', static function (Blueprint $table): void {
            $table->id();
            // No FK/cascade on user_id — consistent with op_log and the rest of
            // the v1 schema (single-user). IN-05 forward-note: when multi-user
            // account deletion lands, that sweep MUST also delete this user's
            // device_registry (and pairing_tokens) rows so no orphans survive.
            $table->unsignedInteger('user_id');
            $table->string('device_id');                 // UUID v4
            $table->string('name');                       // Auto-detected, user-renamable
            $table->string('ed25519_public_key_hex');     // 64 hex chars (32 bytes)
            $table->string('x25519_public_key_hex');      // 64 hex chars (32 bytes)
            $table->text('safety_number_words');          // 6 BIP39 words, space-separated
            $table->integer('is_self')->default(0);       // 1 if this row = the local device
            $table->text('paired_at');                    // ISO8601 UTC
            $table->text('confirmed_at')->nullable();     // NULL until safety-number confirmed
            $table->text('last_seen_at')->nullable();     // Phase 13 stub — never written here
            $table->text('created_at');
            $table->text('updated_at');
        });

        $connection = $this->db()->connection($this->getConnection());

        $connection->statement(
            'CREATE UNIQUE INDEX device_registry_user_device_idx ON device_registry (user_id, device_id)'
        );

        $connection->statement(
            'CREATE INDEX device_registry_user_idx ON device_registry (user_id, confirmed_at)'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('device_registry');
    }

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
