<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates the sync_encryption_state table — per-user, DEVICE-LOCAL at-rest
 * encryption state for Phase 14 (CRYPT-01/CRYPT-02, D-09).
 *
 * Column notes:
 *   - `user_id` UNIQUE — one row per user on THIS device. Not an FK/cascade,
 *     consistent with the rest of the v1 single-user schema precedent
 *     (op_log_entries, device_registry).
 *   - `current_epoch` NULLABLE INTEGER — the GDK keyring epoch id new op-log
 *     writes and new direct-write (import) rows should tag/encrypt under.
 *     NULL means encryption is not yet enabled for this user on this device.
 *   - `migration_in_progress` BOOLEAN, default false — set true for the
 *     duration of the D-09 backup-first atomic encryption pass; gates
 *     read-trust so a crashed/partial migration is never silently trusted
 *     as complete (Plan 06).
 *   - `enabled_at` NULLABLE TIMESTAMP — when encryption was turned on for
 *     this user on this device; NULL until the migration completes.
 *
 * CRITICAL invariant (T-14-01, mirrors device_registry's documented "no
 * secret-key column" rule, STRIDE T-12-02): this table NEVER holds any GDK
 * key material — only the epoch id (a plain integer) and boolean/timestamp
 * state. Secret keys live exclusively in the encrypted GDK keyring file on
 * disk, written/read by Plan 02's GdkKeyringService, wrapped under the
 * app-lock KEK via AppLockKeyWrap (Modules\Auth\Internal\Lock).
 *
 * This table is DEVICE-LOCAL and must NEVER sync: it is deliberately absent
 * from Modules\Sync\Internal\Config\MergeRulesRegistry. Each device's own
 * current_epoch/migration_in_progress reflects that device's own encryption
 * rollout state — replicating it via the op-log would conflate independent
 * per-device migration progress and epoch bookkeeping across peers, which
 * is incoherent (a device catching up mid-rotation must resolve its own
 * epoch from the keyring it receives, not from a synced row).
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('sync_encryption_state', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->integer('current_epoch')->nullable();
            $table->boolean('migration_in_progress')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
        });

        $connection = $this->db()->connection($this->getConnection());

        $connection->statement(
            'CREATE UNIQUE INDEX sync_encryption_state_user_idx ON sync_encryption_state (user_id)'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('sync_encryption_state');
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
