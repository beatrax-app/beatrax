<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the relay_mailbox table — the zero-knowledge ciphertext mailbox
 * for the store-and-forward relay (XPORT-03, D-01/D-02).
 *
 * ZERO-KNOWLEDGE INVARIANT (D-02, T-13-02): the relay stores and forwards
 * opaque encrypted blobs addressed to a recipient device. The relay NEVER
 * decrypts payloads. A relay operator with full DB access learns only:
 *   - sender_did: the sending device_id (routing metadata)
 *   - recipient_did: the receiving device_id (mailbox address)
 *   - blob: opaque Noise ciphertext (cannot be decrypted without session keys)
 *   - timestamps and envelope sizes
 * No user_id, no op-log field names, no plaintext user data ever lands here.
 *
 * Column notes:
 *   - `sender_did` and `recipient_did` are device_id UUIDs (from device_registry).
 *     They do NOT expose user_id — device_id is a UUID that does not map to
 *     a user without the device_registry (which the relay does not hold).
 *     This is the ZK posture per D-02: relay sees only routing metadata.
 *   - `blob` is a BINARY column holding opaque Noise ciphertext — the relay
 *     never calls any sodium function on blob content.
 *   - `delivered_at` is NULL while pending; set to ISO8601 UTC when drained.
 *   - `expires_at` is the ISO8601 UTC GC cutoff (7 days if delivered, 30 days
 *     if not delivered). Relay GC respects this column.
 *   - Deliberately NO user_id column — device_id is the only routing key.
 *
 * One partial index:
 *   - `relay_mailbox_pending_idx` on (recipient_did, delivered_at) WHERE
 *     delivered_at IS NULL — SQLite partial index; must be created via raw SQL
 *     because Blueprint does not support partial indexes.
 *     This index makes the "drain all pending messages for recipient_did" query
 *     efficient without scanning delivered rows.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('relay_mailbox', static function (Blueprint $table): void {
            $table->id();
            $table->string('sender_did');                   // sending device_id (routing only)
            $table->string('recipient_did');                // receiving device_id (mailbox address)
            $table->binary('blob');                         // opaque Noise ciphertext — NEVER decrypted
            $table->text('created_at');                     // ISO8601 UTC — when delivered to relay
            $table->text('delivered_at')->nullable();       // NULL = pending; ISO8601 UTC when drained
            $table->text('expires_at');                     // ISO8601 UTC — GC cutoff
        });

        $connection = $this->db()->connection($this->getConnection());

        // SQLite partial index — not supported via Blueprint; must be raw SQL.
        // The WHERE clause ensures only pending (undelivered) rows are indexed.
        $connection->statement(
            'CREATE INDEX relay_mailbox_pending_idx ON relay_mailbox (recipient_did, delivered_at)'
            .' WHERE delivered_at IS NULL'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('relay_mailbox');
    }
};
