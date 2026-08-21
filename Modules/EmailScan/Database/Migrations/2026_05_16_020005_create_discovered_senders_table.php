<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// UNIQUE (user_id, inbox_id, sender_email) is what keeps a re-run of the
// discovery scan idempotent. The scan only ever writes 'candidate'; the
// promotion / dismissal UI flips the row, and the scan's WHERE clause then
// suppresses it on later runs.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('discovered_senders', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained('inboxes')->cascadeOnDelete();
            $table->string('sender_email', 320);
            $table->string('sender_name', 320)->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('last_seen_at');
            // nullOnDelete, not cascade: the candidate is still actionable once
            // the representative .eml has been purged.
            $table->foreignId('sample_message_id')->nullable()->constrained('inbox_messages')->nullOnDelete();
            $table->string('state', 16)->default('candidate');
            $table->timestamps();

            $table->unique(['user_id', 'inbox_id', 'sender_email']);
            $table->index(['user_id', 'state']);
            $table->index(['user_id', 'occurrence_count']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedStates = "'candidate','added','dismissed'";

        $connection->statement(sprintf(
            "CREATE TRIGGER discovered_senders_state_check_insert BEFORE INSERT ON discovered_senders FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid discovered_senders.state value'); END",
            $allowedStates,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER discovered_senders_state_check_update BEFORE UPDATE OF state ON discovered_senders FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid discovered_senders.state value'); END",
            $allowedStates,
        ));
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('discovered_senders');
    }
};
