<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/notifications/architecture.md#deterministic-deduplication
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('notifications', static function (Blueprint $table): void {
            // A sha256 of (user_id, trigger_type, subject_key, occurrence), so
            // two devices deriving the same tuple converge on one row at insert
            // time rather than needing a post-hoc merge.
            $table->string('id', 64)->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Locally derived from a settlement check, never synced; read_at and
            // dismissed_at are the synced fields and bypass the state machine.
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
            // The sha256 PK is not insertion-ordered, so cursor pagination has
            // to page on (created_at, id) instead.
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
};
