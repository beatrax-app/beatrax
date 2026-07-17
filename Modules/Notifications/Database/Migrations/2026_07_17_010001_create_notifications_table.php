<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates the `notifications` table — one row per deduplicated
 * notification. The primary key is a deterministic sha256 hex digest
 * (D-05) derived by `DeterministicKeyDeriver` from
 * `(user_id, trigger_type, subject_key, occurrence)`; two independent
 * devices computing the same tuple converge on the SAME row id with no
 * sync-engine change required (`OpLogEntry::$pk` is already `int|string`).
 *
 * `state` carries ONLY the Req-13 resolved/withdrawn axis
 * (`open -> resolved`, terminal). It is a LOCALLY-DERIVED column, not a
 * synced field — `NotificationStateMachine` is the sole mutator and
 * `OpLogReplayer` never touches it. `read_at` (D-09, a plain nullable
 * latch) and `dismissed_at` (D-10, a plain nullable reversible timestamp)
 * are BOTH outside the state machine and ARE synced fields, written
 * directly by callers / the op-log replayer.
 *
 * `title` / `body` / `params` / `trigger_type` are encrypted at rest by
 * plan 18-04 via `SensitiveFieldRegistry` — this migration only shapes
 * the columns as plain `text()`/`string()`; it does not register them.
 *
 * Per D-12, `id` / `user_id` / `created_at` / `read_at` / `dismissed_at` /
 * `state` are deliberately NEVER encrypted: the PK is matched in `WHERE`
 * clauses for dedup, and the timestamps drive KEK-less pruning (D-37) and
 * unread counts.
 *
 * The `(user_id, created_at, id)` index backs plan 18-12's compound
 * `(created_at, id)` cursor pagination — the sha256 PK is NOT
 * insertion-ordered, so pagination cannot rely on the PK alone.
 *
 * The two SQLite triggers (mirroring the `anomaly_alerts` pattern)
 * enforce the two-value `state` enum at the DB layer regardless of which
 * code path performed the write (D-39).
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('notifications', static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('state', 16)->default('open');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->text('title');
            $table->text('body');
            $table->text('params')->nullable();
            $table->string('trigger_type', 32);
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'dismissed_at']);
            $table->index(['user_id', 'created_at', 'id']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedStates = "'open','resolved'";

        $connection->statement(sprintf(
            "CREATE TRIGGER notifications_state_check_insert BEFORE INSERT ON notifications FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid notifications.state value'); END",
            $allowedStates,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER notifications_state_check_update BEFORE UPDATE OF state ON notifications FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid notifications.state value'); END",
            $allowedStates,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS notifications_state_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS notifications_state_check_update');

        $this->schema()->dropIfExists('notifications');
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
