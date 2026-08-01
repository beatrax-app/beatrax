<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the pairing_tokens table — the short-lived handshake-state store
 * for the bidirectional device-pairing flow (PAIR-02, D-06/D-13).
 *
 * This is a transient scratch table: a row exists only for the lifetime of
 * one pairing attempt (a short TTL window) and is pruned after confirmation
 * or expiry. The permanent trust store is device_registry.
 *
 * Column notes:
 *   - `token_hash` is SHA-256(token) hex, UNIQUE — the plaintext token is
 *     shown to the user (QR + word-code) and never persisted. A leaked DB
 *     row alone yields no usable token; validation uses hash_equals against
 *     this column (STRIDE T-12-01, RESEARCH Pitfall 3).
 *   - `state` is a plain TEXT enum: pending|awaiting_confirm|confirmed|expired.
 *     NO database ENUM column type — all enums are application-validated TEXT,
 *     consistent with op_log_entries.op_type (project convention).
 *   - `initiator_*` columns carry the issuing device's public identity.
 *   - `responder_*` columns are NULL until the responder accepts the token.
 *   - `initiator_confirmed_at` + `responder_confirmed_at` must BOTH be set
 *     before the state may transition to confirmed (mandatory both-sides
 *     safety-number confirmation, D-07).
 *   - `expires_at` is the ISO8601 UTC expiry; entries past it are rejected.
 *
 * One index:
 *   - `pairing_tokens_user_expires_idx` on (user_id, expires_at, state)
 *     — supports the scoped, not-expired, state-filtered lookup the accept
 *       path performs, plus expired-token pruning.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('pairing_tokens', static function (Blueprint $table): void {
            $table->id();
            // No FK/cascade on user_id — consistent with op_log and the rest of
            // the v1 schema (single-user). IN-05 forward-note: when multi-user
            // account deletion lands, that sweep MUST also delete this user's
            // pairing_tokens (and device_registry) rows so no orphans survive.
            $table->unsignedInteger('user_id');
            $table->string('token_hash')->unique();        // SHA-256(token) hex — never plaintext
            $table->string('initiator_device_id');
            $table->string('initiator_ed25519_pub_hex');
            $table->string('initiator_x25519_pub_hex');
            $table->string('responder_device_id')->nullable();
            $table->string('responder_ed25519_pub_hex')->nullable();
            $table->string('responder_x25519_pub_hex')->nullable();
            $table->string('state')->default('pending');   // pending|awaiting_confirm|confirmed|expired
            $table->text('expires_at');                     // ISO8601 UTC
            $table->text('accepted_at')->nullable();        // when responder submitted credentials
            $table->text('initiator_confirmed_at')->nullable();
            $table->text('responder_confirmed_at')->nullable();
            $table->text('created_at');
        });

        $connection = $this->db()->connection($this->getConnection());

        $connection->statement(
            'CREATE INDEX pairing_tokens_user_expires_idx ON pairing_tokens (user_id, expires_at, state)'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('pairing_tokens');
    }
};
