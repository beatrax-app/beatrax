<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates the sync_sessions table — the in-flight session state for each
 * peer-to-peer Noise session established by the transport layer (XPORT-01/02).
 *
 * Keys on the established (user_id, device_id) composite convention shared
 * by op_log_entries and device_registry. No FK/cascade on user_id or
 * device_id columns — consistent with every other Sync table.
 *
 * Column notes:
 *   - `local_device_id` is the local device's UUID (device_registry.device_id).
 *   - `peer_device_id`  is the remote device's UUID (device_registry.device_id).
 *   - `status` is a plain TEXT column — NO database ENUM type. Application-
 *     validated values: connecting|handshaking|active|closed|failed.
 *   - All timestamps as ISO8601 UTC strings in text() columns, consistent
 *     with device_registry and pairing_tokens (never $table->timestamp()).
 *   - `last_seen_at` is the instant a valid encrypted message last arrived
 *     on this session (Phase 13 writes here; device_registry.last_seen_at
 *     is a parallel column at a higher granularity).
 *   - `connected_at` is set when the session status transitions to active.
 *
 * Two indexes:
 *   - `sync_sessions_user_peer_idx` on (user_id, peer_device_id, status)
 *     — supports the per-user per-peer active-session lookup in the
 *       transport layer without scanning all sessions.
 *   - `sync_sessions_user_peer_unique` UNIQUE on (user_id, local_device_id,
 *     peer_device_id) — enforces at most one session row per ordered device
 *     pair per user; prevents duplicate session accumulation on reconnect.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('sync_sessions', static function (Blueprint $table): void {
            $table->id();
            // No FK/cascade on user_id — consistent with op_log and the rest of
            // the v1 schema (single-user). IN-05 forward-note: when multi-user
            // account deletion lands, that sweep MUST also delete this user's
            // sync_sessions rows so no orphans survive.
            $table->unsignedInteger('user_id');
            $table->string('local_device_id');                   // our device_id (UUID v4)
            $table->string('peer_device_id');                    // their device_id (UUID v4)
            $table->string('status', 32);                        // connecting|handshaking|active|closed|failed
            // NO database ENUM column type — all enums are application-validated TEXT,
            // consistent with op_log_entries.op_type (project convention).
            $table->text('error_message')->nullable();           // human-readable if status='failed'
            $table->text('last_seen_at')->nullable();            // ISO8601 UTC — last successful message
            $table->text('connected_at')->nullable();            // ISO8601 UTC — when session became active
            $table->text('created_at');
            $table->text('updated_at');
        });

        $connection = $this->db()->connection($this->getConnection());

        $connection->statement(
            'CREATE INDEX sync_sessions_user_peer_idx ON sync_sessions (user_id, peer_device_id, status)'
        );

        $connection->statement(
            'CREATE UNIQUE INDEX sync_sessions_user_peer_unique ON sync_sessions (user_id, local_device_id, peer_device_id)'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('sync_sessions');
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
