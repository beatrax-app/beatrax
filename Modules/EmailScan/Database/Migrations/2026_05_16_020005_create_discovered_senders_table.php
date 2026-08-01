<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the discovered_senders table — the candidate queue the
 * discovery-scan job appends to whenever it observes a sender the
 * user has not yet curated into known_senders.
 *
 * One row per (user_id, inbox_id, sender_email) triple — the UNIQUE
 * key keeps re-runs of the discovery scan idempotent. Each row tracks
 * how often the sender has been seen and when, plus a soft FK to a
 * sample message so the UI can show a single representative subject.
 * The sample is nullOnDelete so purging the linked inbox_messages row
 * does not drop the discovered candidate.
 *
 * The `state` column is an enum-shaped string ('candidate' / 'added'
 * / 'dismissed') enforced via paired BEFORE INSERT / BEFORE UPDATE
 * triggers; the discovery scan only ever writes 'candidate'. The
 * promotion / dismissal UI flips the row to 'added' or 'dismissed';
 * the discovery scan's WHERE clause then suppresses those rows on
 * subsequent runs.
 */
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
            // Soft FK — deleting the sample message must not drop the
            // discovered-sender row; the candidate is still actionable
            // even after the representative .eml is gone.
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
